<?php
// report.php — dashboard to view cron-like runs and manually trigger archive writes.
// Writes/reads data/cron.jsonl for log history.

declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');

// ---------- config ----------
date_default_timezone_set('Europe/London');
$dataDir   = __DIR__ . '/data';
$logFile   = $dataDir . '/cron.jsonl';
$jobName   = 'archive_write';
$writerURL = 'https://shout.stiwdio.com/archive_feed.php?write=1&limit=200';

// Ensure data dir exists
if (!is_dir($dataDir)) @mkdir($dataDir, 0755, true);
if (!file_exists($logFile)) @touch($logFile);

// ---------- helpers ----------
function iso_now(): string {
  return (new DateTimeImmutable('now'))->format('c');
}
function client_ip(): string {
  foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $k) {
    if (!empty($_SERVER[$k])) return trim(explode(',', $_SERVER[$k])[0]);
  }
  return '0.0.0.0';
}
function ua(): string {
  return $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function safe_json_decode(string $txt): ?array {
  $j = json_decode($txt, true);
  return is_array($j) ? $j : null;
}
function read_log(string $logFile, int $limit=500): array {
  // read last N lines (simple + safe for modest sizes)
  $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
  $out = [];
  for ($i=count($lines)-1; $i>=0 && count($out)<$limit; $i--){
    $row = safe_json_decode($lines[$i]);
    if ($row) $out[] = $row;
  }
  return $out;
}
function append_log(string $logFile, array $row): void {
  @file_put_contents($logFile, json_encode($row, JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);
}

/**
 * Call the writer endpoint exactly like cron.
 * Returns a normalized array we can log + show:
 *   ok(bool), status('ok'|'fail'), error(string),
 *   written, total, seen_live, considered, duration_ms, raw, url
 */
function run_archive_once(string $url): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT        => 25,
    CURLOPT_USERAGENT      => 'shout-report/1.0',
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
  ]);
  $body = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $err  = curl_error($ch);
  curl_close($ch);

  if ($body === false || $code >= 400) {
    return [
      'ok'=>false,'status'=>'fail','error'=>"http $code","written"=>0,'total'=>0,
      'seen_live'=>0,'considered'=>0,'duration_ms'=>0,'raw'=>null,'url'=>$url
    ];
  }
  $j = safe_json_decode($body);
  if (!$j) {
    return ['ok'=>false,'status'=>'fail','error'=>'bad json','written'=>0,'total'=>0,'seen_live'=>0,'considered'=>0,'duration_ms'=>0,'raw'=>null,'url'=>$url];
  }

  // Be tolerant to field names (different versions)
  $written     = (int)($j['written'] ?? $j['added'] ?? 0);
  $total       = (int)($j['total']   ?? $j['total_estimate'] ?? 0);
  $seen_live   = (int)($j['seen_live'] ?? 0);
  $considered  = (int)($j['considered'] ?? 0);
  $duration_ms = (int)($j['duration_ms'] ?? $j['duration'] ?? 0);
  $ok          = !empty($j['ok']);

  return [
    'ok'=>$ok,
    'status'=>$ok?'ok':'fail',
    'error'=>$j['error'] ?? '',
    'written'=>$written,
    'total'=>$total,
    'seen_live'=>$seen_live,
    'considered'=>$considered,
    'duration_ms'=>$duration_ms,
    'raw'=>$j,
    'url'=>$url
  ];
}

// ---------- handle manual run ----------
$manualResult = null;
if (isset($_GET['run']) && $_GET['run']==='1') {
  $res = run_archive_once($writerURL);
  $manualResult = $res;

  $row = [
    'ts'    => iso_now(),
    'job'   => $jobName,
    'status'=> $res['status'],
    'details'=>[
      'written'=>$res['written'],'total'=>$res['total'],
      'seen_live'=>$res['seen_live'],'considered'=>$res['considered'],
      'duration_ms'=>$res['duration_ms'],
      'error'=>$res['error'] ?? ''
    ],
    'ip'    => client_ip(),
    'ua'    => 'shout-report/1.0'
  ];
  append_log($logFile, $row);
  // avoid re-running on refresh
  header('Location: '.$_SERVER['PHP_SELF']);
  exit;
}

// ---------- read + summarise ----------
$rows = read_log($logFile, 500); // newest first
$totalRuns = count($rows);
$okCount = 0; $failCount = 0;
$lastWritten = 0; $sumWritten = 0; $durations = [];

foreach ($rows as $r) {
  if (($r['status'] ?? '') === 'ok') {
    $okCount++;
    $w = (int)($r['details']['written'] ?? 0);
    $lastWritten = ($lastWritten===0) ? $w : $lastWritten; // first ok row in newest-first order
    $sumWritten += $w;
    $d = (int)($r['details']['duration_ms'] ?? 0);
    if ($d>0) $durations[] = $d;
  } else {
    $failCount++;
  }
}
$avgDur = $durations ? round(array_sum($durations)/count($durations)) : 0;

// Filter switch
$onlyFails = (isset($_GET['fails']) && $_GET['fails']==='1');
if ($onlyFails) {
  $rows = array_values(array_filter($rows, fn($r)=>($r['status']??'')!=='ok'));
}

// ---------- page ----------
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cron Report</title>
<link rel="icon" href="data:,">
<style>
  :root{--bg:#0b0f14;--card:#ffffff10;--border:#ffffff1a;--muted:#9aa4b2;--ok:#16a34a;--bad:#ef4444;--ink:#fff;--pill:#ffffff12}
  *{box-sizing:border-box} body{margin:0;background:var(--bg);color:var(--ink);font-family:system-ui,-apple-system,Segoe UI,Helvetica,Arial}
  .wrap{max-width:1100px;margin:0 auto;padding:24px 16px 48px}
  h1{margin:0 0 12px;font-size:28px}
  .topbar{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin:8px 0 20px}
  .btn{background:var(--pill);border:1px solid var(--border);color:var(--ink);border-radius:12px;padding:10px 12px;cursor:pointer;text-decoration:none}
  .btn:hover{background:#ffffff18}
  .pill{display:inline-flex;align-items:center;gap:8px;background:var(--pill);border:1px solid var(--border);border-radius:12px;padding:10px 12px}
  .tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;margin:16px 0 4px}
  .tile{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:14px}
  .tile h3{margin:0 0 4px;font-size:14px;color:var(--muted);text-transform:uppercase;letter-spacing:.06em}
  .tile .big{font-size:28px}
  .tabs{display:flex;gap:10px;margin:14px 0}
  .tabs a{padding:8px 12px;border:1px solid var(--border);border-radius:12px;color:var(--ink);text-decoration:none;background:var(--pill)}
  .tabs a.active{outline:1px solid #2563eb55;background:#2563eb22}
  table{width:100%;border-collapse:collapse;margin-top:8px}
  th,td{padding:10px;border-bottom:1px solid var(--border);vertical-align:top}
  th{color:var(--muted);font-weight:600;text-align:left}
  .ok{color:var(--ok);font-weight:700}
  .fail{color:var(--bad);font-weight:700}
  .muted{color:var(--muted)}
  pre.box{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:12px;overflow:auto}
</style>
</head>
<body>
<div class="wrap">
  <h1>Cron Report</h1>

  <div class="topbar">
    <a class="btn" href="?run=1">Run archive job now</a>
    <div class="muted">Runs <code>archive_feed.php?write=1&amp;limit=200</code> via HTTPS and logs the run.</div>
  </div>

  <?php if ($manualResult !== null): ?>
  <pre class="box"><?php echo h(json_encode($manualResult, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)); ?></pre>
  <?php endif; ?>

  <div class="tiles">
    <div class="tile">
      <h3>Last run</h3>
      <div class="big">
        <?php
          if ($totalRuns>0) {
            $last = $rows[0];
            echo h($last['ts'] ?? '—'), ' · ',
                 (($last['status'] ?? '')==='ok' ? '<span class="ok">ok</span>' : '<span class="fail">fail</span>');
          } else echo '—';
        ?>
      </div>
    </div>
    <div class="tile">
      <h3>Total runs</h3>
      <div class="big"><?php echo $totalRuns; ?></div>
      <div class="muted"><?php echo $okCount; ?> / <?php echo $failCount; ?></div>
    </div>
    <div class="tile">
      <h3>Last “written” count</h3>
      <div class="big"><?php echo (int)$lastWritten; ?></div>
      <div class="muted">Sum written · Avg duration<br><?php echo (int)$sumWritten; ?> · <?php echo (int)$avgDur; ?>ms</div>
    </div>
  </div>

  <div class="tabs">
    <a class="<?php echo !$onlyFails?'active':''; ?>" href="?">All</a>
    <a class="<?php echo $onlyFails?'active':''; ?>" href="?fails=1">Only fails</a>
    <a class="btn" href="./">Back to Live</a>
  </div>

  <table>
    <thead>
      <tr>
        <th style="width:22%">When</th>
        <th style="width:14%">Job</th>
        <th style="width:10%">Status</th>
        <th>Details</th>
        <th style="width:24%">IP / UA</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="5" class="muted">No entries yet.</td></tr>
      <?php else: foreach ($rows as $r):
        $d = $r['details'] ?? [];
        $summary = [];
        foreach (['written','total','seen_live','considered','duration_ms','dry'] as $k) {
          if (isset($d[$k])) $summary[] = $k.': '.$d[$k];
        }
      ?>
      <tr>
        <td class="muted"><?php echo h($r['ts'] ?? '—'); ?></td>
        <td><span class="pill"><?php echo h($r['job'] ?? '—'); ?></span></td>
        <td><?php echo (($r['status'] ?? '')==='ok' ? '<span class="ok">ok</span>' : '<span class="fail">fail</span>'); ?></td>
        <td><?php echo h($summary ? implode(' · ', $summary) : '—'); ?></td>
        <td class="muted"><?php echo h(($r['ip'] ?? '—').' '.($r['ua'] ?? '')); ?></td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
</body>
</html>