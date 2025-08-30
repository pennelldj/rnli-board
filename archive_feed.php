<?php
// archive_feed.php — read & write the RNLI archive
// Storage: data/launches.jsonl  (one JSON object per line)
// Timezone: Europe/London (for day cut-offs)
// Default read rule: hide Today (and Yesterday unless include_yesterday=1)
//
// Query params (reader):
//   page (default 1), page_size (default 50, max 200)
//   search  (optional; matches shortName/title/website; case-insensitive)
//   since   (optional; ISO or YYYY-MM-DD; applied AFTER the base rule)
//   until   (optional; ISO or YYYY-MM-DD)
//   include_yesterday=1 (optional; moves cutoff from "yesterday 00:00" to "today 00:00")
//
// Writer mode:
//   write=1           (enable writer)
//   limit=N           (default 50) how many to request from RNLI feed
//   dry=1             (optional) do everything except file write (good for testing)
//
// Notes:
// - Writer ONLY archives items with launchDate < TODAY 00:00 (Europe/London).
// - Dedupe by stable id (raw id or cOACS; fallback hash of station+date+title).
// - Logs to data/cron.jsonl so report.php can show status.

header('Content-Type: application/json; charset=utf-8');

// ---------- Paths / Setup ----------
$root     = __DIR__;
$dataDir  = $root . '/data';
$linesFile= $dataDir . '/launches.jsonl';

@mkdir($dataDir, 0775, true);

try { $tz = new DateTimeZone('Europe/London'); } catch(Exception $e) { $tz = new DateTimeZone('UTC'); }
$now          = new DateTime('now', $tz);
$todayStart   = (clone $now)->setTime(0,0,0);
$yesterdayStart = (clone $todayStart)->modify('-1 day');

// ---------- Helpers ----------
function cron_log($job, $status, $fields = []) {
  $row = array_merge([
    'ts'     => (new DateTime('now', new DateTimeZone('Europe/London')))->format('Y-m-d H:i:s'),
    'job'    => $job,
    'status' => $status,
    'ip'     => $_SERVER['REMOTE_ADDR'] ?? '',
    'ua'     => $_SERVER['HTTP_USER_AGENT'] ?? ''
  ], $fields);
  $dir = __DIR__ . '/data';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  @file_put_contents($dir.'/cron.jsonl', json_encode($row, JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);
}

function read_lines_as_items($file) {
  if (!file_exists($file)) return [];
  $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if (!$lines) return [];
  $out = [];
  foreach ($lines as $line) {
    $o = json_decode($line, true);
    if (is_array($o)) $out[] = $o;
  }
  return $out;
}

function write_items_as_lines_append($file, $itemsToAppend) {
  if (!$itemsToAppend) return 0;
  $fh = @fopen($file, 'a');
  if (!$fh) return 0;
  if (flock($fh, LOCK_EX)) {
    foreach ($itemsToAppend as $o) {
      fwrite($fh, json_encode($o, JSON_UNESCAPED_SLASHES)."\n");
    }
    fflush($fh);
    flock($fh, LOCK_UN);
  }
  fclose($fh);
  return count($itemsToAppend);
}

function stable_id_for($x) {
  $id = $x['id'] ?? $x['cOACS'] ?? '';
  if ($id !== '') return $id;
  $sig = ($x['shortName'] ?? '') . '|' . ($x['launchDate'] ?? '') . '|' . ($x['title'] ?? '');
  return 'x_' . substr(sha1($sig), 0, 16);
}

function normalize_rnli($arr) {
  // Map RNLI feed object -> our archive object
  // Keep field names expected by archive.html
  return [
    'id'            => $arr['id'] ?? ($arr['cOACS'] ?? null),
    'shortName'     => $arr['shortName'] ?? ($arr['stationName'] ?? 'Unknown'),
    'title'         => $arr['title'] ?? '',
    'website'       => $arr['website'] ?? '',
    'lifeboat_IdNo' => $arr['lifeboat_IdNo'] ?? '',
    'launchDate'    => $arr['launchDate'] ?? ($arr['timeStamp'] ?? null)
  ];
}

function fetch_rnli_live($limit = 50, &$err = null) {
  $limit = max(1, min(500, intval($limit)));
  $url = 'https://services.rnli.org/api/launches?numberOfShouts=' . $limit;
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 6,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_USERAGENT      => 'stiwdio-archive/1.0 (+https://shout.stiwdio.com)'
  ]);
  $raw  = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $errc = curl_error($ch);
  curl_close($ch);

  if ($raw === false || $code < 200 || $code >= 300) {
    $err = "HTTP $code $errc";
    return null;
  }
  $j = json_decode($raw, true);
  if (!is_array($j)) {
    $err = 'Bad JSON from RNLI';
    return null;
  }
  return $j;
}

// ---------- Params ----------
$page    = max(1, intval($_GET['page'] ?? 1));
$size    = max(1, min(200, intval($_GET['page_size'] ?? 50)));
$search  = trim($_GET['search'] ?? '');
$since   = trim($_GET['since']  ?? '');
$until   = trim($_GET['until']  ?? '');
$includeYesterday = isset($_GET['include_yesterday']) && $_GET['include_yesterday'] == '1';

$doWrite = isset($_GET['write']) && $_GET['write'] == '1';
$limit   = max(1, min(500, intval($_GET['limit'] ?? 50)));
$dry     = isset($_GET['dry']) && $_GET['dry'] == '1';

// ---------- Writer mode ----------
if ($doWrite) {
  $t0 = microtime(true);
  $err = null;

  // Load existing as map[id] = item
  $existing = read_lines_as_items($linesFile);
  $byId = [];
  foreach ($existing as $it) {
    $k = stable_id_for($it);
    $byId[$k] = $it;
  }
  $before = count($byId);

  // Fetch live
  $live = fetch_rnli_live($limit, $err);
  if ($live === null) {
    cron_log('archive_write', 'fail', ['message' => $err ?: 'fetch error']);
    echo json_encode(['ok'=>false,'error'=>$err?:'fetch error']);
    exit;
  }

  // Only accept items older than TODAY 00:00 (Europe/London)
  $cutoff = $todayStart;

  $toAppend = [];
  $seenIds  = 0;
  foreach ($live as $row) {
    $norm = normalize_rnli($row);
    if (!($norm['launchDate'] ?? null)) continue;

    try {
      $t = new DateTime($norm['launchDate']);
      $t->setTimezone($tz);
    } catch(Exception $e) {
      continue;
    }
    if (!($t < $cutoff)) {
      // skip Today
      continue;
    }

    $id = stable_id_for($norm);
    $seenIds++;
    if (!isset($byId[$id])) {
      $byId[$id] = $norm;
      $toAppend[] = $norm;
    } else {
      // Already present; you could update fields if you want (keep as-is to preserve append-only)
    }
  }

  $written = 0;
  if (!$dry && $toAppend) {
    $written = write_items_as_lines_append($linesFile, $toAppend);
  }

  $duration = (int) round((microtime(true) - $t0) * 1000);
  cron_log('archive_write', 'ok', [
    'written'     => $written,
    'total'       => count($byId),
    'seen_live'   => $seenIds,
    'considered'  => count($toAppend),
    'duration_ms' => $duration,
    'dry'         => $dry ? 1 : 0
  ]);

  echo json_encode([
    'ok'         => true,
    'written'    => $written,
    'appended_ids' => $written,  // number of new items added
    'total_after'=> count($byId),
    'duration_ms'=> $duration,
    'dry'        => $dry ? 1 : 0
  ], JSON_UNESCAPED_SLASHES);
  exit;
}

// ---------- Reader mode ----------

// If archive file doesn't exist yet, return empty set
if (!file_exists($linesFile)) {
  echo json_encode([
    'meta'=>['total'=>0,'page'=>$page,'page_size'=>$size,'has_next'=>false,'has_prev'=>false],
    'items'=>[]
  ], JSON_UNESCAPED_SLASHES);
  exit;
}

// Read all lines
$items = read_lines_as_items($linesFile);

// Sort newest-first by launchDate
usort($items, function($a,$b){
  $ta = strtotime($a['launchDate'] ?? '1970-01-01T00:00:00Z');
  $tb = strtotime($b['launchDate'] ?? '1970-01-01T00:00:00Z');
  return $tb <=> $ta;
});

// Base archive rule: ONLY items older than cutoff
// Default cutoff = yesterday 00:00 (hide Today + Yesterday)
// If ?include_yesterday=1, cutoff = today 00:00 (hide Today only)
$cutoff = $includeYesterday ? $todayStart : $yesterdayStart;

$items = array_values(array_filter($items, function($x) use ($tz, $cutoff) {
  $ld = $x['launchDate'] ?? null;
  if (!$ld) return false;
  try {
    $t = new DateTime($ld);
    $t->setTimezone($tz);
  } catch (Exception $e) {
    return false;
  }
  return $t < $cutoff;
}));

// Optional search filter
if ($search !== '') {
  $q = mb_strtolower($search);
  $items = array_values(array_filter($items, function($x) use ($q){
    $hay = mb_strtolower(
      ($x['shortName'] ?? '') . ' ' .
      ($x['title'] ?? '') . ' ' .
      ($x['website'] ?? '')
    );
    return mb_strpos($hay, $q) !== false;
  }));
}

// Optional since/until (apply AFTER base rule)
if ($since !== '') {
  $ts = strtotime($since);
  if ($ts !== false) {
    $items = array_values(array_filter($items, function($x) use ($ts){
      $t = strtotime($x['launchDate'] ?? '') ?: 0;
      return $t >= $ts;
    }));
  }
}
if ($until !== '') {
  $tu = strtotime($until);
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

echo json_encode([
  'meta' => [
    'total'    => $total,
    'page'     => $page,
    'page_size'=> $size,
    'has_next' => ($start + $size) < $total,
    'has_prev' => $page > 1
  ],
  'items' => $slice
], JSON_UNESCAPED_SLASHES);