<?php
/**
 * report.php — Cron/Writer dashboard for shout.stiwdio.com
 *
 * - Reads data/cron.jsonl to show runs (newest first)
 * - Button to run archive writer now (via PHP CLI) and log result
 * - Manual run simulates a normal GET request to archive_feed.php (fixes 400/502)
 * - Heartbeat rows are detected and shown with a heart, and excluded from write stats
 */

declare(strict_types=1);
error_reporting(E_ALL ^ E_NOTICE);
@ini_set('display_errors', '0');

date_default_timezone_set('Europe/London');

$ROOT     = __DIR__;
$DATA_DIR = $ROOT . '/data';
@is_dir($DATA_DIR) || @mkdir($DATA_DIR, 0755, true);
$LOG_FILE = $DATA_DIR . '/cron.jsonl';

function read_log(string $file): array {
  if (!is_file($file)) return [];
  $out = [];
  $fh = @fopen($file, 'r');
  if (!$fh) return [];
  while (!feof($fh)) {
    $line = trim((string)fgets($fh));
    if ($line === '') continue;
    $j = json_decode($line, true);
    if (is_array($j)) $out[] = $j;
  }
  fclose($fh);
  return $out;
}

function append_log(string $file, array $row): void {
  $row['ts'] = $row['ts'] ?? gmdate('c');
  $line = json_encode($row, JSON_UNESCAPED_SLASHES);
  @file_put_contents($file, $line . "\n", FILE_APPEND);
}

function safe_int($v, int $def = 0): int {
  return is_numeric($v) ? (int)$v : $def;
}

function is_heartbeat(array $r): bool {
  if (!empty($r['heartbeat'])) return true;
  if (!empty($r['details']['heartbeat'])) return true;
  // Some crons may log UA only; treat “shout-cron/1.0” with no writer details as heartbeat
  if (!empty($r['ua']) && stripos((string)$r['ua'], 'shout-cron/1.0') !== false && empty($r['details'])) return true;
  // Also allow job label explicitly set
  if (!empty($r['job']) && stripos((string)$r['job'], 'heartbeat') !== false) return true;
  return false;
}

function summarize(array $rows): array {
  $rows = array_values($rows);
  // newest first
  usort($rows, fn($a,$b) => strcmp($b['ts']??'', $a['ts']??''));

  $total = count($rows);
  $ok = 0; $fail = 0;
  $last = $rows[0] ?? null;

  $last_written = 0;
  $sum_written = 0;
  $sum_dur = 0; $dur_n = 0;

  $heartbeat_count = 0;

  foreach ($rows as $r) {
    $status = strtolower((string)($r['status']??''));
    if ($status === 'ok') $ok++; else $fail++;

    if (is_heartbeat($r)) {
      $heartbeat_count++;
      // Heartbeats do not affect “written” or duration averages
      continue;
    }

    $det = $r['details'] ?? [];
    $w   = safe_int($det['written'] ?? $det['added'] ?? 0);
    $d   = safe_int($det['duration_ms'] ?? 0);
    if ($status === 'ok') {
      $sum_written += $w;
      if ($d > 0) { $sum_dur += $d; $dur_n++; }
      if (!$last_written) $last_written = $w; // from newest ok row (non-heartbeat)
    }
  }

  $avg_dur = $dur_n ? (int)round($sum_dur / $dur_n) : 0;

  return [
    'rows'             => $rows,
    'total_runs'       => $total,
    'ok'               => $ok,
    'fail'             => $fail,
    'last'             => $last,
    'last_written'     => $last_written,
    'sum_written'      => $sum_written,
    'avg_duration'     => $avg_dur,
    'heartbeat_count'  => $heartbeat_count,
  ];
}

/**
 * Manual run: execute archive_feed.php via CLI and log the result.
 * We simulate a normal GET to avoid 400/host checks inside the writer.
 */
if (isset($_GET['run'])) {
  $t0      = microtime(true);
  $phpBin  = trim((string)@shell_exec('command -v php')) ?: '/usr/bin/php';
  $writer  = $ROOT . '/archive_feed.php';
  $result  = ['ok' => false];

  if (!is_file($writer) || !is_readable($writer)) {
    $result['error'] = 'writer_missing';
  } else {
    // Simulate a web GET
    $snippet = <<<'PHPCODE'
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST']      = 'shout.stiwdio.com';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['HTTPS']          = 'on';
$_GET['write']  = 1;
$_GET['limit']  = 200;
$_GET['source'] = 'report';
include __DIR__ . '/archive_feed.php';
PHPCODE;

    $cmd = escapeshellcmd($phpBin) . ' -d display_errors=0 -r ' . escapeshellarg($snippet) . ' 2>&1';
    putenv('SHOUT_REPORT=1'); // so the writer knows we’re the dashboard (optional)
    $raw = @shell_exec($cmd);
    $ms  = (int)round((microtime(true) - $t0) * 1000);

    // Parse JSON if any
    $j = json_decode((string)$raw, true);
    if (is_array($j)) {
      $ok  = (bool)($j['ok'] ?? false);
      $written = safe_int($j['added'] ?? $j['written'] ?? 0);
      $total   = safe_int($j['total_estimate'] ?? $j['total'] ?? 0);
      $consid  = safe_int($j['considered'] ?? 0);
      $seen    = safe_int($j['seen_live'] ?? 0);

      $entry = [
        'job'    => 'archive_write',
        'status' => $ok ? 'ok' : 'fail',
        'details'=> [
          'written'     => $written,
          'total'       => $total,
          'considered'  => $consid,
          'seen_live'   => $seen,
          'duration_ms' => $ms,
        ],
        'ip'     => $_SERVER['REMOTE_ADDR'] ?? '',
        'ua'     => 'shout-report/1.0',
      ];
      append_log($LOG_FILE, $entry);

      $result = [
        'ok'          => $ok,
        'written'     => $written,
        'total'       => $total,
        'considered'  => $consid,
        'seen_live'   => $seen,
        'duration_ms' => $ms,
        'raw'         => $j, // pass through
      ];
    } else {
      // Non-JSON output — log as fail with brief error
      $entry = [
        'job'    => 'archive_write',
        'status' => 'fail',
        'details'=> [
          'error'       => trim(substr((string)$raw, 0, 200)) ?: 'no_output',
          'duration_ms' => $ms,
        ],
        'ip'     => $_SERVER['REMOTE_ADDR'] ?? '',
        'ua'     => 'shout-report/1.0',
      ];
      append_log($LOG_FILE, $entry);

      $result = [
        'ok'    => false,
        'error' => $entry['details']['error'],
      ];
    }
  }

  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($result, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
  exit;
}

// --------- Normal page render ---------
$rows = read_log($LOG_FILE);
$sum  = summarize($rows);

// Filters
$onlyFails = isset($_GET['fails']) && $_GET['fails'] === '1';
$show = $sum['rows'];
if ($onlyFails) $show = array_values(array_filter($show, fn($r)=> strtolower((string)$r['status'])==='fail'));

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Cron Report</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{--bg:#0b0f14;--card:#0f141a;--muted:#9aa3af;--ok:#22c55e;--bad:#ef4444;--accent:#f59e0b}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:#fff;font-family:system-ui,-apple-system,Segoe UI,Helvetica,Arial}
  .container{max-width:1100px;margin:0 auto;padding:22px 14px 42px}
  h1{margin:0 0 8px}
  .row{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;margin-top:14px}
  .card{background:var(--card);border:1px solid #ffffff1a;border-radius:14px;padding:14px}
  .pill{display:inline-block;border:1px solid #ffffff33;border-radius:999px;padding:4px 10px;font-size:12px;color:#fff;background:#ffffff10}
  .ok{color:var(--ok)} .bad{color:var(--bad)}
  .muted{color:var(--muted)}
  button{background:#ffffff10;border:1px solid #ffffff22;border-radius:10px;padding:8px 12px;color:#fff;cursor:pointer}
  button:hover{background:#ffffff14}
  .toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px}
  pre{background:#000000; border:1px solid #111; border-radius:10px; padding:10px; color:#ddd; overflow:auto}
  table{width:100%;border-collapse:collapse;margin-top:12px}
  th,td{padding:10px;border-bottom:1px solid #ffffff14;text-align:left;font-size:14px}
  th{color:#cbd5e1;font-weight:600}
  .nowrap{white-space:nowrap}
  a{color:#f59e0b;text-decoration:none}
  a:hover{text-decoration:underline}
  .heart{font-size:16px;color:#ef4444}
</style>
</head>
<body>
<div class="container">
  <h1>Cron Report</h1>

  <div class="toolbar">
    <button id="runBtn">Run archive job now</button>
    <span class="muted">Runs <code>archive_feed.php?write=1&amp;limit=200</code> via PHP CLI and logs the run. Heartbeats show as <span class="heart">❤️</span>.</span>
  </div>

  <div class="row" style="margin-top:12px">
    <div class="card">
      <div class="muted">LAST RUN</div>
      <div style="margin-top:6px;font-family:ui-monospace,monospace">
        <?php if ($sum['last']): ?>
          <span><?=h($sum['last']['ts'])?></span>
          <span> · </span>
          <span class="<?= strtolower($sum['last']['status'])==='ok' ? 'ok' : 'bad' ?>"><?=h($sum['last']['status'])?></span>
          <?php if (is_heartbeat($sum['last'])): ?>
            <span> · <span class="heart" title="Heartbeat">❤️</span></span>
          <?php endif; ?>
        <?php else: ?>
          <span class="muted">No entries yet.</span>
        <?php endif; ?>
      </div>
    </div>
    <div class="card">
      <div class="muted">TOTAL RUNS</div>
      <div style="margin-top:8px;font-size:28px"><?= (int)$sum['total_runs'] ?></div>
      <div class="muted" style="margin-top:4px">
        <span class="ok"><?= (int)$sum['ok'] ?></span> / <span class="bad"><?= (int)$sum['fail'] ?></span> ·
        Heartbeats: <?= (int)$sum['heartbeat_count'] ?>
      </div>
    </div>
    <div class="card">
      <div class="muted">LAST “WRITTEN” COUNT</div>
      <div style="margin-top:8px;font-size:28px"><?= (int)$sum['last_written'] ?></div>
      <div class="muted" style="margin-top:4px">Sum written · Avg duration</div>
      <div style="margin-top:6px"><span><?= (int)$sum['sum_written'] ?></span> · <span><?= (int)$sum['avg_duration'] ?>ms</span></div>
      <div class="muted" style="margin-top:6px;font-size:12px">(* heartbeat runs don’t affect these numbers)</div>
    </div>
  </div>

  <div style="margin-top:16px">
    <div class="toolbar">
      <a class="pill" href="?">All</a>
      <a class="pill" href="?fails=1">Only fails</a>
      <span class="muted">Total matches: <?=count($show)?></span>
    </div>

    <table>
      <thead>
        <tr>
          <th class="nowrap">When</th>
          <th>Job</th>
          <th>Status</th>
          <th>Details</th>
          <th class="nowrap">IP / UA</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!count($show)): ?>
        <tr><td colspan="5" class="muted">No entries yet.</td></tr>
      <?php else:
        foreach ($show as $r):
          $d = $r['details'] ?? [];
          $status = strtolower((string)($r['status'] ?? ''));
          $cls = ($status === 'ok') ? 'ok' : 'bad';
          $hb = is_heartbeat($r);
      ?>
        <tr>
          <td class="nowrap"><?=h($r['ts'] ?? '')?></td>
          <td><span class="pill"><?=h($r['job'] ?? ($hb ? 'heartbeat' : ''))?></span></td>
          <td class="<?=$cls?>"><?=h($r['status'] ?? '')?></td>
          <td>
            <?php if ($hb): ?>
              <span class="heart" title="Heartbeat">❤️</span>
            <?php else:
              $parts = [];
              if (isset($d['written']) || isset($d['added'])) $parts[] = 'written: ' . h((string)($d['written'] ?? $d['added']));
              if (isset($d['total']))       $parts[] = 'total: ' . h((string)$d['total']);
              if (isset($d['seen_live']))   $parts[] = 'seen_live: ' . h((string)$d['seen_live']);
              if (isset($d['considered']))  $parts[] = 'considered: ' . h((string)$d['considered']);
              if (isset($d['duration_ms'])) $parts[] = 'duration_ms: ' . h((string)$d['duration_ms']);
              if (isset($d['error']))       $parts[] = 'error: ' . h((string)$d['error']);
              echo $parts ? implode(' · ', $parts) : '—';
            endif; ?>
          </td>
          <td class="muted"><?=h(($r['ip'] ?? '') . ' ' . ($r['ua'] ?? ''))?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <div style="margin-top:18px">
    <div class="muted">Manual run result:</div>
    <pre id="runOut" aria-live="polite" aria-atomic="true">{ }</pre>
  </div>

  <div class="muted" style="margin-top:12px">
    Reads <code>data/cron.jsonl</code>. Writer URL is <code>archive_feed.php?write=1&amp;limit=200</code> (simulated GET). Timezone: Europe/London.
  </div>
</div>

<script>
document.getElementById('runBtn')?.addEventListener('click', async () => {
  const out = document.getElementById('runOut');
  out.textContent = 'Running…';
  try{
    const res = await fetch('?run=1', {cache:'no-store'});
    const j   = await res.json();
    out.textContent = JSON.stringify(j, null, 2);
    // After a successful run, reload to show new log row
    setTimeout(()=>location.reload(), 600);
  }catch(e){
    out.textContent = JSON.stringify({ok:false, error:String(e)}, null, 2);
  }
});
</script>
</body>
</html>