<?php
// report.php — simple cron/archiver dashboard + manual-run with CLI fallback
// © Stiwdio Ltd 2025

// ------------------------- paths -------------------------
$ROOT     = __DIR__;
$DATA_DIR = $ROOT . '/data';
$CRON_LOG = $DATA_DIR . '/cron.jsonl';  // newline-delimited JSON

@mkdir($DATA_DIR, 0775, true);
if (!file_exists($CRON_LOG)) touch($CRON_LOG);

// ------------------------- helpers -----------------------
function read_cron_log($file){
  $out = [];
  if (!is_file($file)) return $out;
  $fh = fopen($file, 'r');
  if (!$fh) return $out;
  while (($line = fgets($fh)) !== false){
    $line = trim($line);
    if ($line === '') continue;
    $j = json_decode($line, true);
    if (is_array($j)) $out[] = $j;
  }
  fclose($fh);
  return $out;
}

function write_cron_line($file, $row){
  $row['ts'] = $row['ts'] ?? gmdate('c'); // ISO
  $json = json_encode($row, JSON_UNESCAPED_SLASHES);
  file_put_contents($file, $json . "\n", FILE_APPEND | LOCK_EX);
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function ip_addr(){
  foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $k){
    if (!empty($_SERVER[$k])) return explode(',', $_SERVER[$k])[0];
  }
  return '0.0.0.0';
}

// --------------------- manual "run now" -------------------
$manual_result = null;
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['force'] ?? '')==='1'){
  $t0 = microtime(true);

  // Run writer in a separate PHP process: avoids vhosts/proxies/headers entirely.
  $php = trim((string)@shell_exec('command -v php')) ?: '/usr/bin/php';
  $writer = $ROOT . '/archive_feed.php';

  if (is_file($writer) && is_readable($writer)){
    // Build a one-liner: set GET and include the writer
    $snippet = '$_GET["write"]=1; $_GET["limit"]=200; include "'.addslashes($writer).'";';
    $cmd = escapeshellcmd($php) . ' -d detect_unicode=0 -r ' . escapeshellarg($snippet);

    // Identify this caller in the writer (if it logs UA/IP)
    putenv('SHOUT_REPORT=1');

    $out = @shell_exec($cmd);
    $dt_ms = (int)round((microtime(true)-$t0)*1000);

    if ($out === null || $out === false){
      $manual_result = ['ok'=>false, 'error'=>'CLI exec failed (no output)'];
      // log failure
      write_cron_line($CRON_LOG, [
        'job' => 'archive_write',
        'status' => 'fail',
        'details' => ['error'=>'cli/no-output', 'duration_ms'=>$dt_ms],
        'ip' => ip_addr(),
        'ua' => ($_SERVER['HTTP_USER_AGENT'] ?? 'shout-report/1.0'),
      ]);
    } else {
      $decoded = json_decode($out, true);
      if (is_array($decoded)){
        $manual_result = $decoded;
        // normalise a few fields for the log summary
        $det = [
          'written'      => $decoded['added']        ?? ($decoded['written'] ?? null),
          'total'        => $decoded['total']        ?? null,
          'seen_live'    => $decoded['seen_live']    ?? null,
          'considered'   => $decoded['considered']   ?? null,
          'duration_ms'  => $decoded['duration_ms']  ?? $dt_ms,
          'dry'          => $decoded['dry']          ?? null,
        ];
        write_cron_line($CRON_LOG, [
          'job'    => 'archive_write',
          'status' => ($decoded['ok'] ?? false) ? 'ok' : 'fail',
          'details'=> $det,
          'ip'     => ip_addr(),
          'ua'     => ($_SERVER['HTTP_USER_AGENT'] ?? 'shout-report/1.0'),
        ]);
      } else {
        $manual_result = ['ok'=>false, 'error'=>'CLI returned non-JSON', 'raw'=>$out];
        write_cron_line($CRON_LOG, [
          'job' => 'archive_write',
          'status' => 'fail',
          'details' => ['error'=>'cli/non-json', 'duration_ms'=>$dt_ms],
          'ip' => ip_addr(),
          'ua' => ($_SERVER['HTTP_USER_AGENT'] ?? 'shout-report/1.0'),
        ]);
      }
    }
  } else {
    $manual_result = ['ok'=>false, 'error'=>'archive_feed.php not found/readable'];
    write_cron_line($CRON_LOG, [
      'job' => 'archive_write',
      'status' => 'fail',
      'details' => ['error'=>'missing-writer'],
      'ip' => ip_addr(),
      'ua' => ($_SERVER['HTTP_USER_AGENT'] ?? 'shout-report/1.0'),
    ]);
  }
}

// ----------------------- load history ---------------------
$rows = read_cron_log($CRON_LOG);
// sort newest first
usort($rows, function($a,$b){
  return strcmp($b['ts'] ?? '', $a['ts'] ?? '');
});

// ----------------------- filters/ui -----------------------
$flt_status = $_GET['status'] ?? 'any';   // any | ok | fail
$flt_job    = $_GET['job']    ?? 'archive_write';
$page_size  = max(10, min(200, intval($_GET['page_size'] ?? 50)));
$page       = max(1, intval($_GET['page'] ?? 1));

$filtered = array_values(array_filter($rows, function($r) use ($flt_status, $flt_job){
  if ($flt_job && isset($r['job']) && $flt_job !== '' && $r['job'] !== $flt_job) return false;
  if ($flt_status === 'ok'   && ($r['status'] ?? '') !== 'ok')   return false;
  if ($flt_status === 'fail' && ($r['status'] ?? '') !== 'fail') return false;
  return true;
}));

$total_matches = count($filtered);
$start = ($page-1)*$page_size;
$view  = array_slice($filtered, $start, $page_size);

// summary tiles
$last = $rows[0] ?? null;
$last_written = 0;
if ($last && ($last['status'] ?? '')==='ok'){
  $det = $last['details'] ?? [];
  $last_written = intval($det['written'] ?? 0);
}
$success = 0; $fail = 0; $sum_written = 0; $durations = [];
foreach ($rows as $r){
  if (($r['status'] ?? '') === 'ok') $success++; else $fail++;
  $w = intval(($r['details']['written'] ?? 0));
  $sum_written += $w;
  if (isset($r['details']['duration_ms'])) $durations[] = intval($r['details']['duration_ms']);
}
$avg_duration = $durations ? round(array_sum($durations)/count($durations)) : 0;
$total_runs   = count($rows);

// ----------------------- html -----------------------------
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cron Report</title>
<link rel="icon" href="data:,">
<style>
:root{--bg:#0b0f14;--card:#ffffff10;--border:#ffffff1a;--muted:#94a3b8;--ok:#22c55e;--fail:#ef4444;--brand:#f28b00}
*{box-sizing:border-box} body{margin:0;background:var(--bg);color:#fff;font-family:system-ui,-apple-system,Segoe UI,Helvetica,Arial}
a{color:#f28b00} .wrap{max-width:1100px;margin:0 auto;padding:24px 16px 48px}
h1{margin:0 0 14px;font-size:28px}
.controls{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:6px 0 14px}
.btn{background:#ffffff12;border:1px solid var(--border);color:#fff;border-radius:10px;padding:8px 12px;cursor:pointer}
input,select{background:#ffffff0a;border:1px solid var(--border);color:#fff;border-radius:10px;padding:8px 10px}
.tileRow{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin:10px 0 18px}
.tile{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:12px}
.k{color:#cbd5e1;font-size:12px;text-transform:uppercase}
.v{font-size:22px;margin-top:4px}
.ok{color:var(--ok)} .fail{color:var(--fail)}
pre{background:#00000044;border:1px solid var(--border);border-radius:12px;padding:10px;overflow:auto}
.table{width:100%;border-collapse:collapse;margin-top:10px}
.table th,.table td{border-bottom:1px solid var(--border);padding:10px 6px;text-align:left;font-size:14px}
.badge{display:inline-block;padding:2px 8px;border-radius:999px;background:#ffffff10;border:1px solid var(--border)}
.badge.ok{background:#09351a;border-color:#0e6b2e} .badge.fail{background:#3a0d0d;border-color:#7a1f1f}
.foot{margin-top:18px;color:var(--muted);font-size:13px}
</style>
</head>
<body>
<div class="wrap">
  <h1>Cron Report</h1>

  <form method="post" class="controls" style="gap:12px">
    <button class="btn" type="submit" name="force" value="1">Run archive job now</button>
    <span class="foot">Runs <code>archive_feed.php?write=1&amp;limit=200</code> via PHP CLI and logs like cron.</span>
  </form>

  <?php if ($manual_result !== null): ?>
    <div class="tile" style="margin:12px 0">
      <div class="k">Manual run result:</div>
      <pre><?=h(json_encode($manual_result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))?></pre>
    </div>
  <?php endif; ?>

  <div class="tileRow">
    <div class="tile"><div class="k">Last run</div>
      <div class="v"><?=h($last['ts'] ?? '—')?> ·
        <?php $ls=($last['status']??'')==='ok'?'ok':'fail'; ?>
        <span class="<?=$ls?>"><?=h($last['status'] ?? '—')?></span>
      </div>
    </div>
    <div class="tile"><div class="k">Total runs</div>
      <div class="v"><?=h($total_runs)?></div>
    </div>
    <div class="tile"><div class="k">Success / Fail</div>
      <div class="v"><span class="ok"><?=h($success)?></span> / <span class="fail"><?=h($fail)?></span></div>
    </div>
    <div class="tile"><div class="k">Last “written” count</div>
      <div class="v"><?=h($last_written)?></div>
      <div class="k" style="margin-top:6px">Sum written · Avg duration</div>
      <div class="v"><?=h($sum_written)?> · <?=h($avg_duration)?>ms</div>
    </div>
  </div>

  <form method="get" class="controls">
    <input type="hidden" name="job" value="<?=h($flt_job)?>">
    <label>Status
      <select name="status">
        <option value="any"  <?=$flt_status==='any'?'selected':''?>>Any</option>
        <option value="ok"   <?=$flt_status==='ok'?'selected':''?>>ok</option>
        <option value="fail" <?=$flt_status==='fail'?'selected':''?>>fail</option>
      </select>
    </label>
    <label>Page size
      <select name="page_size">
        <?php foreach([25,50,100,200] as $ps): ?>
          <option value="<?=$ps?>" <?=$page_size===$ps?'selected':''?>><?=$ps?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="btn" type="submit">Apply</button>
    <span class="foot">Total matches: <?=h($total_matches)?></span>
  </form>

  <table class="table">
    <thead><tr>
      <th>When</th><th>Job</th><th>Status</th><th>Details</th><th>IP / UA</th>
    </tr></thead>
    <tbody>
    <?php if (!$view): ?>
      <tr><td colspan="5" class="foot">No entries yet.</td></tr>
    <?php else: foreach ($view as $r):
      $d = $r['details'] ?? [];
      $badge = ($r['status'] ?? '')==='ok' ? 'ok' : 'fail';
      $detBits = [];
      foreach (['written','total','seen_live','considered','dry','duration_ms'] as $k){
        if (isset($d[$k])) $detBits[] = $k.': '.$d[$k];
      }
      $detStr = $detBits ? implode(' · ', $detBits) : '—';
    ?>
      <tr>
        <td><?=h($r['ts'] ?? '—')?></td>
        <td><span class="badge"><?=h($r['job'] ?? '—')?></span></td>
        <td><span class="badge <?=$badge?>"><?=h($r['status'] ?? '—')?></span></td>
        <td><?=h($detStr)?></td>
        <td><?=h(($r['ip'] ?? ''))?><br><span class="foot"><?=h($r['ua'] ?? '')?></span></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>

  <div class="foot">Page <?=h($page)?> · Showing <?=h(count($view))?> / <?=h($total_matches)?></div>

  <div class="foot" style="margin-top:16px">
    Reads <code>data/cron.jsonl</code>. Manual runs use PHP CLI and append an entry to the log.
  </div>
  <div class="foot"><a href="./">Back to Live</a></div>
</div>
</body>
</html>