<?php
// archive_feed.php — RNLI launch archive (JSONL, append-only)
// - Reader (default): returns ONLY items older than 24 hours (rolling window)
//     Params: page, page_size, search, since, until
//     Flags:  include_last24=1  (or legacy include_yesterday=1) to also include last 24h
// - Writer:  ?write=1 [&limit=200] [&dry=1]
//     Appends RNLI launches older than 24h into data/launches.jsonl (deduped by id/cOACS)
//     Logs every run to data/cron.jsonl (consumed by report.php)

header('Content-Type: application/json; charset=utf-8');

// ----------------------------- config -----------------------------
$ROOT    = __DIR__;
$DATA    = $ROOT . '/data';
$LINES   = $DATA . '/launches.jsonl';   // archive store (JSON Lines)
$CRONLOG = $DATA . '/cron.jsonl';       // writer runs (for report.php)
$TZNAME  = 'Europe/London';
$MAX_PAGE = 300;
$MAX_SIZE = 50;

try { $TZ = new DateTimeZone($TZNAME); } catch (Exception $e) { $TZ = new DateTimeZone('UTC'); }

// ----------------------------- utils ------------------------------
function jecho($arr, $code=200) {
  http_response_code($code);
  echo json_encode($arr, JSON_UNESCAPED_SLASHES);
  exit;
}

function ensure_data_dir($dir) {
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
}

function cron_log($job, $status, $fields = []) {
  global $CRONLOG, $TZ;
  ensure_data_dir(dirname($CRONLOG));
  $row = array_merge([
    'ts'     => (new DateTime('now', $TZ))->format('Y-m-d H:i:s'),
    'job'    => $job,
    'status' => $status,
    'ip'     => $_SERVER['REMOTE_ADDR'] ?? '',
    'ua'     => $_SERVER['HTTP_USER_AGENT'] ?? '',
  ], $fields);
  @file_put_contents($CRONLOG, json_encode($row, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

// Try to pull RNLI feed directly (server-side; CORS irrelevant)
function fetch_rnli($limit = 500, &$err = null) {
  $limit = max(1, min(500, intval($limit)));
  $url = "https://services.rnli.org/api/launches?numberOfShouts={$limit}";
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_HTTPHEADER     => [
      'User-Agent: shout-board/1.0 (+https://shout.stiwdio.com)'
    ],
  ]);
  $body = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $cerr = curl_error($ch);
  curl_close($ch);

  if ($body === false || $code >= 400) {
    $err = "http {$code} {$cerr}";
    return null;
  }
  $j = json_decode($body, true);
  if (!is_array($j)) {
    $err = "bad json";
    return null;
  }
  return $j;
}

function stable_id($x) {
  // Prefer RNLI id/cOACS; fallback to hash of short fields
  $id = $x['id'] ?? null;
  if (!$id && isset($x['cOACS'])) $id = $x['cOACS'];
  if ($id) return (string)$id;
  $seed = ($x['shortName'] ?? '') . '|' . ($x['launchDate'] ?? '') . '|' . ($x['title'] ?? '');
  return 'h:' . substr(sha1($seed), 0, 16);
}

// --------------------------- query params -------------------------
$page   = max(1, min($MAX_PAGE, intval($_GET['page'] ?? 1)));
$size   = max(1, min($MAX_SIZE, intval($_GET['page_size'] ?? 50)));
$search = trim($_GET['search'] ?? '');
$sinceQ = trim($_GET['since'] ?? '');
$untilQ = trim($_GET['until'] ?? '');

$includeLast24 = (isset($_GET['include_last24']) && $_GET['include_last24'] == '1')
              || (isset($_GET['include_yesterday']) && $_GET['include_yesterday'] == '1'); // legacy

$doWrite = (isset($_GET['write']) && $_GET['write'] == '1');
$limit   = max(1, min(500, intval($_GET['limit'] ?? 50)));
$dry     = (isset($_GET['dry']) && $_GET['dry'] == '1');

// --------------------------- writer branch ------------------------
if ($doWrite) {
  $t0 = microtime(true);
  $err = null;
  $live = fetch_rnli($limit, $err);
  if (!$live) {
    cron_log('archive_write', 'fail', ['message' => $err ?: 'fetch error']);
    jecho(['ok' => false, 'error' => $err ?: 'fetch error'], 502);
  }

  // Normalize a tiny subset we store (keep names consistent with reader)
  $norm = [];
  $seenIds = 0;
  foreach ($live as $x) {
    $seenIds++;
    $o = [
      'id'            => stable_id($x),
      'shortName'     => $x['shortName']   ?? '',
      'launchDate'    => $x['launchDate']  ?? '',
      'title'         => $x['title']       ?? '',
      'website'       => $x['website']     ?? '',
      'lifeboat_IdNo' => $x['lifeboat_IdNo'] ?? '',
      'cOACS'         => $x['cOACS'] ?? ($x['id'] ?? null),
    ];
    $norm[] = $o;
  }

  // Load current archive into ID set (fast path)
  ensure_data_dir($DATA);
  $byId = [];
  if (file_exists($LINES)) {
    $fh = fopen($LINES, 'r');
    if ($fh) {
      while (($line = fgets($fh)) !== false) {
        $j = json_decode($line, true);
        if ($j && isset($j['id'])) $byId[(string)$j['id']] = true;
        elseif ($j && isset($j['cOACS'])) $byId[(string)$j['cOACS']] = true;
      }
      fclose($fh);
    }
  }

  // 24h cutoff in Europe/London
  try { $now = new DateTime('now', $TZ); } catch (Exception $e) { $now = new DateTime('now'); }
  $cut = (clone $now)->modify('-24 hours');

  // Select candidates: older than 24h AND not already present
  $toAppend = [];
  foreach ($norm as $o) {
    $ld = $o['launchDate'] ?? '';
    if (!$ld) continue;
    try { $t = new DateTime($ld); $t->setTimezone($TZ); } catch (Exception $e) { continue; }
    if ($t < $cut) {
      $key = (string)($o['id'] ?? $o['cOACS'] ?? '');
      if (!$key) continue;
      if (!isset($byId[$key])) {
        $toAppend[] = $o;
      }
    }
  }

  // Append with exclusive lock
  $written = 0;
  if (!$dry && count($toAppend)) {
    $fh = fopen($LINES, 'a');
    if ($fh) {
      if (flock($fh, LOCK_EX)) {
        foreach ($toAppend as $o) {
          fwrite($fh, json_encode($o, JSON_UNESCAPED_SLASHES) . "\n");
          $written++;
          $byId[(string)($o['id'] ?? $o['cOACS'])] = true;
        }
        fflush($fh);
        flock($fh, LOCK_UN);
      }
      fclose($fh);
    }
  }

  $duration = (int)round((microtime(true) - $t0) * 1000);

  // Unconditional cron log (so report.php always sees runs)
  cron_log('archive_write', 'ok', [
    'written'     => $written,
    'total'       => count($byId),
    'seen_live'   => $seenIds,
    'considered'  => count($toAppend),
    'duration_ms' => $duration,
    'dry'         => $dry ? 1 : 0,
  ]);

  jecho([
    'ok'            => true,
    'written'       => $written,
    'total_estimate'=> count($byId),
    'limit_requested'=> $limit,
    'duration_ms'   => $duration,
    'dry'           => $dry ? 1 : 0,
    'source'        => 'direct'
  ]);
}

// --------------------------- reader branch ------------------------

// If archive file doesn't exist yet
if (!file_exists($LINES)) {
  jecho([
    'meta'=>['total'=>0,'page'=>$page,'page_size'=>$size,'has_next'=>false,'has_prev'=>false],
    'items'=>[]
  ]);
}

// Load all (OK for tens of thousands; can index later if needed)
$items = [];
$fh = fopen($LINES, 'r');
if ($fh) {
  while (($line = fgets($fh)) !== false) {
    $j = json_decode($line, true);
    if ($j) $items[] = $j;
  }
  fclose($fh);
}

// Sort newest first
usort($items, function($a,$b){
  $ta = strtotime($a['launchDate'] ?? '1970-01-01T00:00:00Z');
  $tb = strtotime($b['launchDate'] ?? '1970-01-01T00:00:00Z');
  return $tb <=> $ta;
});

// Time cutoffs
try { $now = new DateTime('now', $TZ); } catch (Exception $e) { $now = new DateTime('now'); }
$cut24 = (clone $now)->modify('-24 hours');

// Base filter: only older than 24h, unless include_last24=1 (or include_yesterday=1)
$items = array_values(array_filter($items, function($x) use ($cut24, $includeLast24, $TZ){
  $ld = $x['launchDate'] ?? '';
  if (!$ld) return false;
  try { $t = new DateTime($ld); $t->setTimezone($TZ); } catch (Exception $e) { return false; }
  if ($includeLast24) return $t < (new DateTime('now', $TZ)); // include everything up to "now"
  return $t < $cut24;
}));

// Optional text search
if ($search !== '') {
  $q = mb_strtolower($search);
  $items = array_values(array_filter($items, function($x) use ($q){
    $hay = mb_strtolower(($x['shortName'] ?? '') . ' ' . ($x['title'] ?? '') . ' ' . ($x['website'] ?? ''));
    return mb_strpos($hay, $q) !== false;
  }));
}

// Optional since/until (applied after base rule)
if ($sinceQ !== '') {
  $ts = strtotime($sinceQ);
  if ($ts !== false) {
    $items = array_values(array_filter($items, function($x) use ($ts){
      $t = strtotime($x['launchDate'] ?? '') ?: 0;
      return $t >= $ts;
    }));
  }
}
if ($untilQ !== '') {
  $tu = strtotime($untilQ);
  if ($tu !== false) {
    $items = array_values(array_filter($items, function($x) use ($tu){
      $t = strtotime($x['launchDate'] ?? '') ?: 0;
      return $t <= $tu;
    }));
  }
}

// Pagination
$total = count($items);
$start = ($page - 1) * $size;
$slice = array_slice($items, $start, $size);

jecho([
  'meta' => [
    'total'     => $total,
    'page'      => $page,
    'page_size' => $size,
    'has_next'  => ($start + $size) < $total,
    'has_prev'  => $page > 1
  ],
  'items' => $slice
]);