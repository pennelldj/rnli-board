<?php
// report.php — compact viewer for data/cron.jsonl (newest first)
// shows a summary, quick filters, and a paginated table..

header('Content-Type: text/html; charset=utf-8');

$logFile = __DIR__ . '/data/cron.jsonl';

$page     = max(1, intval($_GET['page'] ?? 1));
$size     = max(10, min(200, intval($_GET['page_size'] ?? 50)));
$onlyFail = isset($_GET['only_fail']) && $_GET['only_fail'] === '1';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$rows = [];
if (is_readable($logFile)) {
  $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  // newest first
  foreach (array_reverse($lines) as $line) {
    $o = json_decode($line, true);
    if (!$o) continue;
    if ($onlyFail && strtolower($o['status'] ?? '') !== 'fail') continue;
    $rows[] = $o;
  }
}

// Summary stats (over ALL entries, not just filtered)
$allRows = [];
if (is_readable($logFile)) {
  $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  foreach (array_reverse($lines) as $line) {
    $o = json_decode($line, true);
    if ($o) $allRows[] = $o;
  }
}
$totalAll   = count($allRows);
$okCount    = 0;
$failCount  = 0;
$lastRun    = $totalAll ? $allRows[0] : null; // newest first
$lastWritten= null;

foreach ($allRows as $r) {
  $st = strtolower($r['status'] ?? '');
  if ($st === 'ok')   $okCount++;
  if ($st === 'fail') $failCount++;
  if ($lastWritten === null && isset($r['written'])) $lastWritten = $r['written'];
}

// Paging (for filtered view)
$total = count($rows);
$start = ($page - 1) * $size;
$view  = array_slice($rows, $start, $size);

// Helpers for links
function q($overrides = []) {
  $q = $_GET;
  foreach ($overrides as $k=>$v) {
    if ($v === null) { unset($q[$k]); } else { $q[$k] = $v; }
  }
  return http_build_query($q);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cron Report</title>
<style>
  :root{--bg:#0b0f14;--card:#ffffff10;--border:#ffffff1a;--muted:#94a3b8;--rnli:#f28b00;--ok:#22c55e;--fail:#ef4444}
  body{margin:0;background:var(--bg);color:#fff;font-family:system-ui,-apple-system,Segoe UI,Helvetica,Arial}
  .wrap{max-width:1100px;margin:0 auto;padding:24px 16px 48px}
  h1{margin:0 0 6px;font-size:28px}
  a{color:var(--rnli);text-decoration:none}
  a:hover{text-decoration:underline}
  .row{display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin:12px 0}

  .summary{display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));margin:12px 0}
  .card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:12px}
  .k{color:#cbd5e1;font-size:12px;text-transform:uppercase;letter-spacing:.05em}
  .v{font-size:18px;margin-top:4px}
  .ok{color:var(--ok)} .fail{color:var(--fail)}
  .pill{display:inline-block;border:1px solid var(--border);border-radius:999px;padding:4px 10px;font-size:13px}
  .pill.active{outline:1px solid #2563eb55;background:#2563eb22}

  select,button{background:#ffffff0d;border:1px solid var(--border);color:#fff;border-radius:10px;padding:8px 10px}
  table{width:100%;border-collapse:collapse;margin-top:12px}
  th,td{padding:10px;border-bottom:1px solid var(--border);vertical-align:top;font-size:14px}
  th{color:#cbd5e1;text-align:left}
  .note{color:var(--muted)}
  .pager{margin-top:14px;display:flex;gap:10px;align-items:center}
</style>
</head>
<body>
<div class="wrap">
  <h1>Cron Report</h1>

  <!-- Summary -->
  <div class="summary">
    <div class="card">
      <div class="k">Last run</div>
      <div class="v">
        <?= $lastRun ? h($lastRun['ts'] ?? '') : '<span class="note">—</span>' ?>
        <?php if ($lastRun): ?>
          &nbsp;·&nbsp;<b class="<?= strtolower($lastRun['status'] ?? '')==='ok'?'ok':'fail' ?>">
            <?= h($lastRun['status'] ?? '') ?>
          </b>
        <?php endif; ?>
      </div>
    </div>
    <div class="card">
      <div class="k">Total runs</div>
      <div class="v"><?= $totalAll ?></div>
    </div>
    <div class="card">
      <div class="k">Success / Fail</div>
      <div class="v"><span class="ok"><?= $okCount ?></span> / <span class="fail"><?= $failCount ?></span></div>
    </div>
    <div class="card">
      <div class="k">Last “written” count</div>
      <div class="v"><?= $lastWritten !== null ? h($lastWritten) : '<span class="note">—</span>' ?></div>
    </div>
  </div>

  <!-- Filters -->
  <div class="row">
    <a class="pill <?= !$onlyFail?'active':'' ?>" href="?<?= q(['page'=>1,'only_fail'=>null]) ?>">All</a>
    <a class="pill <?= $onlyFail?'active':'' ?>" href="?<?= q(['page'=>1,'only_fail'=>'1']) ?>">Only fails</a>

    <form method="get" style="margin-left:auto;display:flex;gap:8px;align-items:center">
      <?php if ($onlyFail): ?><input type="hidden" name="only_fail" value="1"><?php endif; ?>
      <label>Page size
        <select name="page_size" onchange="this.form.submit()">
          <?php foreach([25,50,100,150,200] as $opt){ ?>
            <option value="<?=$opt?>" <?= $size===$opt?'selected':'' ?>><?=$opt?></option>
          <?php } ?>
        </select>
      </label>
      <noscript><button type="submit">Apply</button></noscript>
    </form>

    <div class="pill">Total matches: <?= $total ?></div>
  </div>

  <!-- Table -->
  <table>
    <thead>
      <tr>
        <th style="width:180px">When</th>
        <th style="width:110px">Job</th>
        <th style="width:80px">Status</th>
        <th>Details</th>
        <th style="width:180px">IP / UA</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$view){ ?>
        <tr><td colspan="5" class="note">No entries yet.</td></tr>
      <?php } else foreach($view as $r){ ?>
        <tr>
          <td><?= h($r['ts'] ?? '') ?></td>
          <td><span class="pill"><?= h($r['job'] ?? '') ?></span></td>
          <td class="<?= strtolower($r['status'] ?? '')==='ok'?'ok':'fail' ?>"><?= h($r['status'] ?? '') ?></td>
          <td>
            <?php
              $keys = ['written','total','seen_live','considered','duration_ms','message','note','dry'];
              $parts = [];
              foreach ($keys as $k) if (isset($r[$k])) $parts[] = "<b>$k</b>: ".h($r[$k]);
              echo $parts ? implode(' · ', $parts) : '<span class="note">—</span>';
            ?>
          </td>
          <td class="note">
            <?= h($r['ip'] ?? '') ?><br>
            <span class="note"><?= h($r['ua'] ?? '') ?></span>
          </td>
        </tr>
      <?php } ?>
    </tbody>
  </table>

  <!-- Pager -->
  <div class="pager">
    <?php $hasPrev = $start>0; $hasNext = ($start+$size)<$total; ?>
    <?php if ($hasPrev){ ?><a href="?<?= q(['page'=>$page-1]) ?>">← Prev</a><?php } ?>
    <div>Page <?= $page ?> · Showing <?= count($view) ?> / <?= $total ?></div>
    <?php if ($hasNext){ ?><a href="?<?= q(['page'=>$page+1]) ?>">Next →</a><?php } ?>
    <div style="margin-left:auto"><a href="./">Back to Live</a></div>
  </div>
</div>
</body>
</html>