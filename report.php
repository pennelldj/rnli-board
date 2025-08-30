<?php
// report.php — viewer for data/cron.jsonl (PHP 7 compatible)
// Adds a robust "Run archive job now" trigger that uses 127.0.0.1 with Host header,
// then falls back to the public URL if needed.

$TZ_NAME = 'Europe/London';
$DATA    = __DIR__ . '/data';
$CRON    = $DATA . '/cron.jsonl';

// Figure host + writer URLs
$host   = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$publicArchive   = $scheme . '://' . $host . '/archive_feed.php?write=1&limit=200';
$loopbackArchive = 'http://127.0.0.1/archive_feed.php?write=1&limit=200';

header('Content-Type: text/html; charset=utf-8');

// ---------- helpers ----------
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function parse_ts($s, $tzName){
  if(!$s) return false;
  try { $tz = new DateTimeZone($tzName ?: 'UTC'); } catch(Exception $e){ $tz = new DateTimeZone('UTC'); }
  $dt = DateTime::createFromFormat('Y-m-d H:i:s', $s, $tz);
  if ($dt instanceof DateTime) return $dt;
  $ts = @strtotime($s);
  if ($ts !== false) {
    $dt = new DateTime('@'.$ts);
    $dt->setTimezone($tz);
    return $dt;
  }
  return false;
}

function read_jsonl($path){
  $out = [];
  if (!file_exists($path)) return $out;
  $fh = @fopen($path, 'r');
  if (!$fh) return $out;
  while (($line = fgets($fh)) !== false){
    $line = trim($line);
    if ($line==='') continue;
    $j = json_decode($line, true);
    if (is_array($j)) $out[] = $j;
  }
  fclose($fh);
  return $out;
}

function pill($text, $kind){
  $bg='#ffffff12'; $bd='#ffffff1f'; $fg='#fff';
  if ($kind==='ok'){ $bg='#16a34a1f'; $bd='#16a34a55'; $fg='#86efac'; }
  elseif ($kind==='fail'){ $bg='#ef44441f'; $bd='#ef444455'; $fg='#fecaca'; }
  elseif ($kind==='muted'){ $bg='#ffffff10'; $bd='#ffffff1a'; $fg='#cbd5e1'; }
  return '<span style="display:inline-block;padding:6px 10px;border-radius:999px;border:1px solid '.$bd.';background:'.$bg.';color:'.$fg.';font-weight:600">'.$text.'</span>';
}

function link_with($key, $val){
  $q = $_GET;
  unset($q['page']); // reset page when filters change
  $q[$key] = $val;
  return '?'.http_build_query($q);
}

// Robust curl getter (supports Host header; returns [body,error])
function curl_get($url, $timeout = 12, $hostHeader = null){
  if (!function_exists('curl_init')) return [null, 'curl not available'];
  $ch = curl_init($url);
  $headers = ['User-Agent: shout-report/1.0'];
  if ($hostHeader) $headers[] = 'Host: '.$hostHeader;
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => min(6, $timeout),
    CURLOPT_TIMEOUT        => $timeout,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
  ]);
  $body = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);
  if ($body === false || $code >= 400) return [null, "HTTP $code ".trim($err)];
  return [$body, null];
}

// ---------- manual trigger ("Run now") ----------
$run_now_result = null;
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['force']) && $_POST['force']=='1'){
  // Prefer loopback (no CDN/reverse-proxy), keep Host header for vhost
  list($body, $err) = curl_get($loopbackArchive, 12, $host);
  if ($err){
    // fallback to public URL
    list($body2, $err2) = curl_get($publicArchive, 12, null);
    if ($err2){
      $run_now_result = ['ok'=>false, 'error'=>$err.'; fallback: '.$err2];
    } else {
      $decoded = json_decode($body2, true);
      $run_now_result = is_array($decoded) ? $decoded : ['raw'=>$body2];
    }
  } else {
    $decoded = json_decode($body, true);
    $run_now_result = is_array($decoded) ? $decoded : ['raw'=>$body];
  }
}

// ---------- query ----------
$q_status = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : '';
$q_job    = isset($_GET['job']) ? trim($_GET['job']) : '';
$page     = max(1, (int)($_GET['page'] ?? 1));
$size     = (int)($_GET['size'] ?? 50);
if ($size < 10) $size = 10;
if ($size > 200) $size = 200;

// ---------- data ----------
$all = read_jsonl($CRON);

// newest first
usort($all, function($a,$b) use ($TZ_NAME){
  $ta = 0; $tb = 0;
  if (isset($a['ts'])) { $d = parse_ts($a['ts'], $TZ_NAME); if ($d) $ta = $d->getTimestamp(); }
  if (isset($b['ts'])) { $d = parse_ts($b['ts'], $TZ_NAME); if ($d) $tb = $d->getTimestamp(); }
  return $tb - $ta;
});

$rows = $all;

// filters
if ($q_status === 'fail'){
  $tmp=[]; foreach ($rows as $r){ if (strtolower($r['status'] ?? '')==='fail') $tmp[]=$r; } $rows=$tmp;
} elseif ($q_status === 'ok'){
  $tmp=[]; foreach ($rows as $r){ if (strtolower($r['status'] ?? '')==='ok') $tmp[]=$r; } $rows=$tmp;
}
if ($q_job !== ''){
  $tmp=[]; foreach ($rows as $r){ if (isset($r['job']) && strcasecmp($r['job'],$q_job)===0) $tmp[]=$r; } $rows=$tmp;
}

// summaries
$total_runs = count($rows);
$total_all  = count($all);
$succ=0; $fail=0; $sum_written=0; $sum_dur=0; $dur_count=0;
$last_run_ts = $total_all ? ($all[0]['ts'] ?? '') : '';
$last_run_status = $total_all ? strtolower($all[0]['status'] ?? '') : '';
$last_nonzero_written = null;

foreach ($rows as $r){
  $st = strtolower($r['status'] ?? '');
  if ($st==='ok') $succ++;
  elseif ($st==='fail') $fail++;

  if (isset($r['written'])){
    $w = (int)$r['written'];
    $sum_written += $w;
    if ($last_nonzero_written === null && $w > 0) $last_nonzero_written = $w;
  }
  if (isset($r['duration_ms'])){ $sum_dur += (int)$r['duration_ms']; $dur_count++; }
}
$avg_dur = $dur_count ? (int)round($sum_dur / $dur_count) : 0;

// next cron due (assume every 30 mins)
$next_due_str = '—';
if ($last_run_ts){
  $last_dt = parse_ts($last_run_ts, $TZ_NAME);
  if ($last_dt){ $next = clone $last_dt; $next->modify('+30 minutes'); $next_due_str = $next->format('Y-m-d H:i:s'); }
}

// pagination
$start = ($page - 1) * $size;
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
  :root{--bg:#0b0f14;--card:#ffffff10;--border:#ffffff1a;--muted:#94a3b8}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:#fff;font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial}
  a{color:#fbbf24;text-decoration:none} a:hover{text-decoration:underline}
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
  .badge-ok{color:#86efac}.badge-fail{color:#fecaca}
  .callout{margin:10px 0;padding:10px 12px;border-radius:12px;border:1px solid #ffffff1a;background:#ffffff0d}
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
          echo ' &nbsp; ' . ($last_run_status==='ok' ? pill('ok','ok') : ($last_run_status==='fail' ? pill('fail','fail') : pill('—','muted')));
        ?>
      </div>
    </div>
    <div class="tile">
      <h3>Next cron due (every 30m)</h3>
      <div class="big"><?= h($next_due_str) ?></div>
    </div>
    <div class="tile">
      <h3>Total runs (filtered / all)</h3>
      <div class="big"><?= h($total_runs) ?> / <span class="muted"><?= h($total_all) ?></span></div>
    </div>
    <div class="tile">
      <h3>Success / Fail</h3>
      <div class="big"><?= ($fail>0 ? pill($succ.' / '.$fail,'fail') : pill($succ.' / '.$fail,'ok')) ?></div>
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

  <!-- Run now -->
  <form method="post" class="callout">
    <input type="hidden" name="force" value="1">
    <button type="submit" class="btn">Run archive job now</button>
    <span class="muted">Tries <code class="mono">http://127.0.0.1/archive_feed.php</code> (Host <?= h($host) ?>), then <?= h($publicArchive) ?>.</span>
  </form>

  <?php if ($run_now_result !== null): ?>
    <div class="callout mono">
      <div><strong>Manual run result:</strong></div>
      <pre style="white-space:pre-wrap;word-break:break-word"><?php echo h(json_encode($run_now_result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)); ?></pre>
    </div>
  <?php endif; ?>

  <form class="filters" method="get">
    <div class="seg">
      <a href="<?= h(link_with('status','')) ?>" class="<?= $q_status===''?'active':'' ?>">All</a>
      <a href="<?= h(link_with('status','fail')) ?>" class="<?= $q_status==='fail'?'active':'' ?>">Only fails</a>
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
            if (isset($r['written']))     $parts[] = 'written: '.(int)$r['written'];
            if (isset($r['total']))       $parts[] = 'total: '.(int)$r['total'];
            if (isset($r['seen_live']))   $parts[] = 'seen_live: '.(int)$r['seen_live'];
            if (isset($r['considered']))  $parts[] = 'considered: '.(int)$r['considered'];
            if (isset($r['duration_ms'])) $parts[] = 'duration_ms: '.(int)$r['duration_ms'];
            if (isset($r['dry']))         $parts[] = 'dry: '.(int)$r['dry'];
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
    if ($total_pages > 1):
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

  <footer>
    Reads <code class="mono">data/cron.jsonl</code>. Writer URLs: 
    <code class="mono"><?= h($loopbackArchive) ?></code> → 
    <code class="mono"><?= h($publicArchive) ?></code>. Timezone: <?= h($TZ_NAME) ?>.
  </footer>
</div>
</body>
</html>