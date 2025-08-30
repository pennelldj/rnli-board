<?php
// cron_report.php — simple viewer for data/cron.jsonl
header('Content-Type: text/html; charset=utf-8');

$logFile = __DIR__ . '/data/cron.jsonl';
$page    = max(1, intval($_GET['page'] ?? 1));
$size    = max(10, min(200, intval($_GET['page_size'] ?? 50)));
$jobFilt = trim($_GET['job'] ?? '');
$statFilt= trim($_GET['status'] ?? '');

$rows = [];
if (is_readable($logFile)) {
  $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  foreach (array_reverse($lines) as $line) { // newest first
    $o = json_decode($line, true);
    if (!$o) continue;
    if ($jobFilt && strcasecmp($o['job'] ?? '', $jobFilt) !== 0) continue;
    if ($statFilt && strcasecmp($o['status'] ?? '', $statFilt) !== 0) continue;
    $rows[] = $o;
  }
}

$total = count($rows);
$start = ($page-1)*$size;
$view  = array_slice($rows, $start, $size);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cron Report</title>
<style>
  :root{--bg:#0b0f14;--card:#ffffff10;--border:#ffffff1a;--muted:#94a3b8}
  body{margin:0;background:var(--bg);color:#fff;font-family:system-ui,-apple-system,Segoe UI,Helvetica,Arial}
  .wrap{max-width:1100px;margin:0 auto;padding:24px 16px 48px}
  h1{margin:0 0 6px;font-size:24px}
  .toolbar{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0}
  input,select,button{background:#ffffff0d;border:1px solid var(--border);color:#fff;border-radius:10px;padding:8px 10px}
  table{width:100%;border-collapse:collapse;margin-top:12px}
  th,td{padding:10px;border-bottom:1px solid var(--border);vertical-align:top;font-size:14px}
  th{color:#cbd5e1;text-align:left}
  .ok{color:#22c55e}.fail{color:#ef4444}.note{color:var(--muted)}
  .pill{display:inline-block;border:1px solid var(--border);border-radius:999px;padding:2px 8px;font-size:12px}
  .pager{margin-top:14px;display:flex;gap:10px;align-items:center}
  a{color:#f28b00;text-decoration:none}
  a:hover{text-decoration:underline}
</style></head><body><div class="wrap">
  <h1>Cron Report</h1>
  <div class="toolbar">
    <form method="get">
      <label>Job <input name="job" value="<?=h($jobFilt)?>" placeholder="archive_write"></label>
      <label>Status
        <select name="status">
          <option value="">(any)</option>
          <option value="ok"   <?= $statFilt==='ok'?'selected':'' ?>>ok</option>
          <option value="fail" <?= $statFilt==='fail'?'selected':'' ?>>fail</option>
        </select>
      </label>
      <label>Page size
        <select name="page_size">
          <?php foreach([25,50,100,150,200] as $opt){ ?>
            <option value="<?=$opt?>" <?= $size===$opt?'selected':'' ?>><?=$opt?></option>
          <?php } ?>
        </select>
      </label>
      <button type="submit">Apply</button>
    </form>
    <div class="pill">Total matches: <?=$total?></div>
  </div>

  <table>
    <thead><tr>
      <th style="width:180px">When</th>
      <th style="width:140px">Job</th>
      <th style="width:80px">Status</th>
      <th>Details</th>
      <th style="width:140px">IP / UA</th>
    </tr></thead>
    <tbody>
    <?php if (!$view){ ?>
      <tr><td colspan="5" class="note">No entries yet.</td></tr>
    <?php } else foreach($view as $r){ ?>
      <tr>
        <td><?=h($r['ts'] ?? '')?></td>
        <td><span class="pill"><?=h($r['job'] ?? '')?></span></td>
        <td class="<?=($r['status']??'')==='ok'?'ok':'fail'?>"><?=h($r['status'] ?? '')?></td>
        <td>
          <?php
            $keys = ['written','total','duration_ms','message','note'];
            $parts = [];
            foreach ($keys as $k) if (isset($r[$k])) $parts[] = "<b>$k</b>: ".h($r[$k]);
            echo $parts ? implode(' · ', $parts) : '<span class="note">—</span>';
          ?>
        </td>
        <td class="note">
          <?=h($r['ip'] ?? '')?><br>
          <span class="note"><?=h($r['ua'] ?? '')?></span>
        </td>
      </tr>
    <?php } ?>
    </tbody>
  </table>

  <div class="pager">
    <?php $hasPrev = $start>0; $hasNext = ($start+$size)<$total;
      $q = $_GET; ?>
    <?php if ($hasPrev){ $q['page']=$page-1; ?><a href="?<?=http_build_query($q)?>">← Prev</a><?php } ?>
    <div>Page <?=$page?> · Showing <?=count($view)?> / <?=$total?></div>
    <?php if ($hasNext){ $q['page']=$page+1; ?><a href="?<?=http_build_query($q)?>">Next →</a><?php } ?>
    <div style="margin-left:auto"><a href="./">Back to Live</a></div>
  </div>
</div></body></html>