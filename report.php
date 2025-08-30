<?php
// report.php — simple viewer for data/cron.jsonl
// Compatible with archive_feed.php cron_log rows:
// {
//   ts, job, status, ip, ua, written, total, seen_live, considered, duration_ms, dry
// }

$TZ     = new DateTimeZone('Europe/London');
$DATA   = __DIR__ . '/data';
$CRON   = $DATA . '/cron.jsonl';

header('Content-Type: text/html; charset=utf-8');

// -------- helpers --------
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function read_jsonl($path){
  $out = [];
  if (!file_exists($path)) return $out;
  $fh = fopen($path, 'r');
  if (!$fh) return $out;
  while (($line = fgets($fh)) !== false){
    $line = trim($line);
    if ($line === '') continue;
    $j = json_decode($line, true);
    if (is_array($j)) $j['_raw'] = $line; // keep raw
    if ($j) $out[] = $j;
  }
  fclose($fh);
  return $out;
}
function parse_ts($s){
  // Expect "YYYY-MM-DD HH:MM:SS"
  $dt = DateTime::createFromFormat('Y-m-d H:i:s', $s, new DateTimeZone('Europe/London'));
  if (!$dt) { // fallback
    $ts = strtotime($s);
    if ($ts !== false) $dt = (new DateTime('@'.$ts))->setTimezone(new DateTimeZone('Europe/London'));
  }
  return $dt;
}
function pill($text, $kind=''){
  $bg = '#ffffff12'; $bd = '#ffffff1f'; $fg = '#fff';
  if ($kind==='ok')    { $bg='#16a34a1f'; $bd='#16a34a55'; $fg='#86efac'; }
  if ($kind==='fail')  { $bg='#ef44441f'; $bd='#ef444455'; $fg='#fecaca'; }
  if ($kind==='muted') { $bg='#ffffff10'; $bd='#ffffff1a'; $fg='#cbd5e1'; }
  return '<span style="display:inline-block;padding:6px 10px;border-radius:999px;border:1px solid '.$bd.';background:'.$bg.';color:'.$fg.';font-weight:600">'.$text.'</span>';
}

// -------- query --------
$q_status = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : '';   // '', 'fail', 'ok'
$q_job    = isset($_GET['job'])    ? trim($_GET['job']) : '';                  // e.g. 'archive_write'
$page     = max(1, intval($_GET['page'] ?? 1));
$size     = max(10, min(200, intval($_GET['size'] ?? 50)));

$all = read_jsonl($CRON);

// Newest → oldest (by ts string; we also handle missing/invalid ts)
usort($all, function($a,$b){
  $ta = parse_ts($a['ts'] ?? '')?->getTimestamp() ?? 0;
  $tb = parse_ts($b['ts'] ?? '')?->getTimestamp() ?? 0;
  return $tb <=> $ta;
});

// Filters
$rows = $all;
if ($q_status === 'fail') $rows = array_values(array_filter($rows, fn($r)=>(strtolower($r['status']??'')==='fail')));
if ($q_status === 'ok')   $rows = array_values(array_filter($rows, fn($r)=>(strtolower($r['status']??'')==='ok')));
if ($q_job !== '')        $rows = array_values(array_filter($rows, fn($r)=> (isset($r['job']) && strcasecmp($r['job'],$q_job)===0)));

// Summary
$total_runs   = count($rows);
$total_all    = count($all);
$succ = 0; $fail = 0; $sum_written = 0; $sum_dur = 0; $dur_count=0;
$last_run_ts = $all ? ($all[0]['ts'] ?? '') : '';
$last_nonzero_written = null;

foreach ($rows as $r){
  $st = strtolower($r['status'] ?? '');
  if ($st==='ok') $succ++; elseif ($st==='fail') $fail++;
  if (isset($r['written'])) {
    $w = intval($r['written']);
    $sum_written += $w;
    if ($last_nonzero_written===null && $w>0) $last_nonzero_written = $w;
  }
  if (isset($r['duration_ms'])) { $sum_dur += intval($r['duration_ms']); $dur_count++; }
}
$avg_dur = $dur_count ? intval(round($sum_dur / $dur_count)) : 0;

// Pagination
$start = ($page-1)*$size;
$paginated = array_slice($rows, $start, $size);

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cron Report</title>
<link rel="icon" href="data:,">
<style>
  :root{--bg:#0b0f14;--card:#ffffff10;--border:#ffffff1a;--muted:#94a3b8;--brand:#f28b00}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:#fff;font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial}
  a{color:#fbbf24;text-decoration:none}
  a:hover{text-decoration:underline}
  .wrap{max-width:1100px;margin:0 auto;padding:24px 16px 60px}
  h1{margin:0 0 16px}
  .row{display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:18px}
  .tile{flex:1 1 220px;min-width:220px;background:var(--card);border:1px solid var(--border);border-radius:14px;padding:12px}
  .tile h3{margin:0 0 6px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#cbd5e1}
  .tile .big{font-size:18px;font-weight:700}
  .filters{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:10px 0 14px}
  input,select,button{background:#ffffff0d;border:1px solid var(--border);color:#fff;border-radius:12px;padding:8px 10px}
  .btn{cursor:pointer}
  .seg{display:inline-flex;border:1px solid var(--border);border-radius:12px;overflow:hidden}
  .seg a{display:inline-block;padding:8px 12px;color:#fff;text-decoration:none;border-right:1px solid var(--border)}
  .seg a:last-child{border-right:0}
  .seg .active{background:#2563eb22;outline:1px solid #2563eb55}
  table{width:100%;border-collapse:collapse}
  th,td{padding:12px;border-bottom:1px solid var(--border);vertical-align:top}
  th{color:#cbd5e1;font-weight:600;text-align:left}
  .muted{color:var(--muted)}
  .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}
  .right{float:right}
  .badge-ok{color:#86efac}
  .badge-fail{color:#fecaca}
  footer{margin-top:18px;color:#ffffff66;font-size:12px}
</style>
</head>
<body>
<div class="wrap">
  <h1>Cron Report</h1>

  <div class="row">
    <div class="tile">
      <h3>Last run</h3>
      <div class="big">
        <?= $last_run_ts ? h($last_run_ts) : '—' ?>
        <?php
          // status of very latest (from $all)
          $latest_status = $all ? strtolower($all[0]['status'] ?? '') : '';
          echo ' &nbsp; ' . ($latest_status==='ok' ? pill('ok','ok') : ($latest_status==='fail' ? pill('fail','fail') : pill('—','muted')));
        ?>
      </div>
    </div>
    <div class="tile">
      <h3>Total runs (filtered / all)</h3>
      <div class="big"><?= h($total_runs) ?> / <span class="muted"><?= h($total_all) ?></span></div>
    </div>
    <div class="tile">
      <h3>Success / Fail</h3>
      <div class="big"><?= pill($succ.' / '.$fail, $fail>0?'fail':'ok') ?></div>
    </div>
    <div class="tile">
      <h3>Last “written” count</h3>
      <div class="big"><?= $last_nonzero_written!==null ? h($last_nonzero_written) : '0' ?></div>
    </div>
    <div class="tile">
      <h3>Sum written · Avg duration</h3>
      <div class="big"><?= h($sum_written) ?> &middot; <?= h($avg_dur) ?>ms</div>
    </div>
  </div>

  <form class="filters" method="get">
    <div class="seg">
      <?php
        $base = function($k,$v){ $q=$_GET; unset($q['page']); $q[$k]=$v; return '?'.http_build_query($q); };
      ?>
      <a href="<?= h($base('status','')) ?>" class="<?= $q_status===''?'active':'' ?>">All</a>
      <a href="<?= h($base('status','fail')) ?>" class="<?= $q_status==='fail'?'active':'' ?>">Only fails</a>
    </div>

    <label>Job
      <input type="text" name="job" value="<?= h($q_job) ?>" placeholder="archive_write">
    </label>
    <label>Status
      <select name="status">
        <option value=""   <?= $q_status===''?'selected':'' ?>>Any</option>
        <option value="ok" <?= $q_status==='ok'?'selected':'' ?>>ok</option>
        <option value="fail" <?= $q_status==='fail'?'selected':'' ?>>fail</option>
      </select>
    </label>
    <label>Page size
      <select name="size">
        <?php foreach([25,50,100,200] as $n): ?>
          <option value="<?= $n ?>" <?= $size===$n?'selected':'' ?>><?= $n ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="btn">Apply</button>
    <div class="right"><a href="./">Back to Live</a></div>
  </form>

  <div class="muted" style="margin-bottom:8px">Total matches: <?= h(count($rows)) ?></div>

  <table>
    <thead>
      <tr>
        <th>When</th>
        <th>Job</th>
        <th>Status</th>
        <th>Details</th>
        <th>IP / UA</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!count($paginated)): ?>
        <tr><td colspan="5" class="muted">No entries yet.</td></tr>
      <?php else: foreach ($paginated as $r): ?>
        <tr>
          <td class="mono"><?= h($r['ts'] ?? '—') ?></td>
          <td><?= pill(h($r['job'] ?? '—'), 'muted') ?></td>
          <td>
            <?php
              $st = strtolower($r['status'] ?? '');
              echo $st==='ok' ? '<span class="badge-ok">ok</span>' :
                   ($st==='fail' ? '<span class="badge-fail">fail</span>' : h($st));
            ?>
          </td>
          <td class="mono">
            <?php
              $parts = [];
              if (isset($r['written']))     $parts[] = 'written: '.intval($r['written']);
              if (isset($r['total']))       $parts[] = 'total: '.intval($r['total']);
              if (isset($r['seen_live']))   $parts[] = 'seen_live: '.intval($r['seen_live']);
              if (isset($r['considered']))  $parts[] = 'considered: '.intval($r['considered']);
              if (isset($r['duration_ms'])) $parts[] = 'duration_ms: '.intval($r['duration_ms']);
              if (isset($r['dry']))         $parts[] = 'dry: '.intval($r['dry']);
              echo $parts ? h(implode(' · ', $parts)) : '<span class="muted">—</span>';
            ?>
          </td>
          <td class="mono">
            <?= h($r['ip'] ?? '') ?><br>
            <span class="muted"><?= h($r['ua'] ?? '') ?></span>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>

  <?php
    $total_pages = max(1, (int)ceil(count($rows)/$size));
    if ($total_pages>1):
      $q = $_GET;
      $q['page'] = max(1, $page-1); $prev = '?'.http_build_query($q);
      $q['page'] = min($total_pages, $page+1); $next = '?'.http_build_query($q);
  ?>
  <div class="row" style="justify-content:space-between;margin-top:12px">
    <div>Page <?= h($page) ?> · Showing <?= h(count($paginated)) ?> / <?= h(count($rows)) ?></div>
    <div>
      <?php if ($page>1): ?><a class="btn" href="<?= h($prev) ?>">Prev</a><?php endif; ?>
      <?php if ($page<$total_pages): ?><a class="btn" href="<?= h($next) ?>">Next</a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <footer>Reads <code class="mono">data/cron.jsonl</code>. Timezone: Europe/London.</footer>
</div>
</body>
</html>