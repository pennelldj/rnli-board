<?php
// ── report runner+logger shim ────────────────────────────────────────────────
// Drop this at the very top of report.php. It adds ?run=cron and ?run=manual
// endpoints that call the existing writer and append a line to data/cron.jsonl.
// It does NOT alter your page markup or styling.

if (!isset($__report_init)) {
  $__report_init = true;
  @date_default_timezone_set('Europe/London');

  $ROOT      = __DIR__;
  $DATA_DIR  = $ROOT . '/data';
  $LOG_FILE  = $DATA_DIR . '/cron.jsonl';
  $WRITER_URL= 'https://shout.stiwdio.com/archive_feed.php?write=1&limit=200';

  @is_dir($DATA_DIR) || @mkdir($DATA_DIR, 0755, true);
  @file_exists($LOG_FILE) || @touch($LOG_FILE);

  function __report_append(array $row): void {
    global $LOG_FILE;
    $row['ts'] = $row['ts'] ?? gmdate('c');
    @file_put_contents($LOG_FILE, json_encode($row, JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);
  }

  function __report_run_writer(): array {
    global $WRITER_URL;
    $ch = curl_init($WRITER_URL);
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

    $res = [
      'ok'          => false,
      'status'      => 'fail',
      'error'       => '',
      'written'     => 0,
      'total'       => 0,
      'seen_live'   => 0,
      'considered'  => 0,
      'duration_ms' => 0,
    ];

    if ($body === false || $code >= 400) {
      $res['error'] = "http $code " . trim($err);
      return $res;
    }

    $j = json_decode($body, true);
    if (!is_array($j)) {
      $res['error'] = 'bad json';
      return $res;
    }

    $res['ok']          = !empty($j['ok']);
    $res['status']      = $res['ok'] ? 'ok' : 'fail';
    $res['error']       = $j['error'] ?? '';
    $res['written']     = (int)($j['written'] ?? $j['added'] ?? 0);
    $res['total']       = (int)($j['total'] ?? $j['total_estimate'] ?? 0);
    $res['seen_live']   = (int)($j['seen_live'] ?? 0);
    $res['considered']  = (int)($j['considered'] ?? 0);
    $res['duration_ms'] = (int)($j['duration_ms'] ?? $j['duration'] ?? 0);
    return $res;
  }

  // Endpoint: /report.php?run=cron (for crontab) or ?run=manual (button)
  if (isset($_GET['run']) && in_array($_GET['run'], ['cron','manual'], true)) {
    $res = __report_run_writer();
    __report_append([
      'job'    => 'archive_write',
      'status' => $res['status'],
      'details'=> [
        'written'     => $res['written'],
        'total'       => $res['total'],
        'seen_live'   => $res['seen_live'],
        'considered'  => $res['considered'],
        'duration_ms' => $res['duration_ms'],
        'error'       => $res['error'],
      ],
      'ip'     => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '')),
      'ua'     => $_GET['run']==='cron' ? 'rnli-cron/1.0' : 'shout-report/1.0',
    ]);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($res, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
    exit;
  }
}
// ─────────────────────────────────────────────────────────────────────────────
?>

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
</style>
</head>
<body>
<div class="container">
  <h1>Cron Report</h1>

  <div class="toolbar">
    <button id="runBtn">Run archive job now</button>
    <span class="muted">Runs <code>archive_feed.php?write=1&amp;limit=200</code> via PHP CLI and logs the run.</span>
  </div>

  <div class="row" style="margin-top:12px">
    <div class="card">
      <div class="muted">LAST RUN</div>
      <div style="margin-top:6px;font-family:ui-monospace,monospace">
        <?php if ($sum['last']): ?>
          <span><?=h($sum['last']['ts'])?></span>
          <span> · </span>
          <span class="<?= strtolower($sum['last']['status'])==='ok' ? 'ok' : 'bad' ?>"><?=h($sum['last']['status'])?></span>
        <?php else: ?>
          <span class="muted">No entries yet.</span>
        <?php endif; ?>
      </div>
    </div>
    <div class="card">
      <div class="muted">TOTAL RUNS</div>
      <div style="margin-top:8px;font-size:28px"><?= (int)$sum['total_runs'] ?></div>
      <div class="muted" style="margin-top:4px">
        <span class="ok"><?= (int)$sum['ok'] ?></span> / <span class="bad"><?= (int)$sum['fail'] ?></span>
      </div>
    </div>
    <div class="card">
      <div class="muted">LAST “WRITTEN” COUNT</div>
      <div style="margin-top:8px;font-size:28px"><?= (int)$sum['last_written'] ?></div>
      <div class="muted" style="margin-top:4px">Sum written · Avg duration</div>
      <div style="margin-top:6px"><span><?= (int)$sum['sum_written'] ?></span> · <span><?= (int)$sum['avg_duration'] ?>ms</span></div>
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
      ?>
        <tr>
          <td class="nowrap"><?=h($r['ts'] ?? '')?></td>
          <td><span class="pill"><?=h($r['job'] ?? '')?></span></td>
          <td class="<?=$cls?>"><?=h($r['status'] ?? '')?></td>
          <td>
            <?php
              $parts = [];
              if (isset($d['written']) || isset($d['added'])) $parts[] = 'written: ' . h((string)($d['written'] ?? $d['added']));
              if (isset($d['total']))       $parts[] = 'total: ' . h((string)$d['total']);
              if (isset($d['seen_live']))   $parts[] = 'seen_live: ' . h((string)$d['seen_live']);
              if (isset($d['considered']))  $parts[] = 'considered: ' . h((string)$d['considered']);
              if (isset($d['duration_ms'])) $parts[] = 'duration_ms: ' . h((string)$d['duration_ms']);
              if (isset($d['error']))       $parts[] = 'error: ' . h((string)$d['error']);
              echo $parts ? implode(' · ', $parts) : '—';
            ?>
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