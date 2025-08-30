<?php
// archive_feed.php — archive API + writer
// - READ mode (default): serves paginated archive from data/launches.jsonl
// - WRITE mode (?write=1): fetches RNLI live API, selects 24h+ items, appends new to launches.jsonl
//
// Query (read):
//   page=1 page_size=50 (max 200)
//   search (in shortName/title/website)
//   since, until (ISO or YYYY-MM-DD), applied AFTER base rule
//   include_yesterday=1  (reader ONLY; shows yesterday on archive)
//
// Query (write):
//   write=1              (enables writer)
//   limit=50             (how many to fetch from RNLI, max 200)
//   source=cron|report   (optional, for logging visibility)
//
// Files:
//   data/launches.jsonl  (newline-delimited JSON; newest-first or append order)
//   data/cron.jsonl      (newline-delimited JSON log lines for report.php)
//
// Timezone: Europe/London

header('Content-Type: application/json; charset=utf-8');

$ROOT     = __DIR__;
$DATA_DIR = $ROOT . '/data';
@is_dir($DATA_DIR) || @mkdir($DATA_DIR, 0755, true);

$LINES_FILE = $DATA_DIR . '/launches.jsonl';
$CRON_FILE  = $DATA_DIR . '/cron.jsonl';

function jout($arr, $code = 200){
  http_response_code($code);
  echo json_encode($arr, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  exit;
}

function now_london(): DateTime {
  try { $tz = new DateTimeZone('Europe/London'); } catch (Exception $e) { $tz = new DateTimeZone('UTC'); }
  return new DateTime('now', $tz);
}
function to_london($iso): ?DateTime {
  if (!$iso) return null;
  try {
    $tz = new DateTimeZone('Europe/London');
    $dt = new DateTime($iso);
    return $dt->setTimezone($tz);
  } catch (Exception $e){ return null; }
}
function launch_id(array $x): ?string {
  if (!empty($x['id'])) return (string)$x['id'];
  if (!empty($x['cOACS'])) return (string)$x['cOACS'];
  // Fallback: synthesize from station + time
  if (!empty($x['shortName']) && !empty($x['launchDate'])) return substr(sha1($x['shortName'].'|'.$x['launchDate']),0,16);
  return null;
}
function norm_item(array $x): ?array {
  // Normalize live RNLI API item → archive line shape
  $id = launch_id($x);
  if (!$id) return null;
  $obj = [
    'id'            => $id,
    'shortName'     => $x['shortName']     ?? ($x['stationName'] ?? 'Unknown'),
    'launchDate'    => $x['launchDate']    ?? ($x['timeStamp'] ?? null),
    'title'         => $x['title']         ?? '',
    'website'       => $x['website']       ?? '',
    'lifeboat_IdNo' => $x['lifeboat_IdNo'] ?? '',
  ];
  return $obj['launchDate'] ? $obj : null;
}
function read_lines_file($file): array {
  if (!is_file($file)) return [];
  $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
  $out = [];
  foreach ($lines as $line){
    $j = json_decode($line, true);
    if ($j) $out[] = $j;
  }
  return $out;
}
function write_line_append($file, array $row): bool {
  return (bool)@file_put_contents($file, json_encode($row, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}
function log_cron($file, array $row): void {
  $row['ts'] = $row['ts'] ?? gmdate('c');
  @file_put_contents($file, json_encode($row, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

// ---------------------------------------------------------
// WRITE MODE: ?write=1 — pull live, select 24h+, append new
// ---------------------------------------------------------
if (isset($_GET['write']) && $_GET['write'] == '1') {
  $t0 = microtime(true);
  $limit = max(1, min(200, intval($_GET['limit'] ?? 50)));

  // RNLI live API (server-side; no CORS issues)
  $api = "https://services.rnli.org/api/launches?numberOfShouts={$limit}";

  // Fetch
  $ch = curl_init($api);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT        => 25,
    CURLOPT_USERAGENT      => 'rnli-board/1.0 (+shout.stiwdio.com)',
  ]);
  $body = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $cerr = curl_error($ch);
  curl_close($ch);

  if ($body === false || $code >= 400) {
    $out = [
      'ok' => false,
      'error' => "live_fetch http $code " . trim($cerr),
      'seen_live' => 0,
      'considered'=> 0,
      'added'     => 0,
      'total'     => is_file($LINES_FILE) ? count(file($LINES_FILE)) : 0,
      'duration_ms'=> (int)round((microtime(true)-$t0)*1000),
    ];
    // log failure
    log_cron($CRON_FILE, [
      'job' => 'archive_write',
      'status' => 'fail',
      'details' => [
        'error' => $out['error'],
        'duration_ms' => $out['duration_ms'],
      ],
      'ip' => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '')),
      'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
      'src'=> $_GET['source'] ?? '',
    ]);
    jout($out, 200);
  }

  $live = json_decode($body, true);
  if (!is_array($live)) {
    $out = [
      'ok' => false,
      'error' => 'live_fetch bad json',
      'seen_live' => 0,
      'considered'=> 0,
      'added'     => 0,
      'total'     => is_file($LINES_FILE) ? count(file($LINES_FILE)) : 0,
      'duration_ms'=> (int)round((microtime(true)-$t0)*1000),
    ];
    log_cron($CRON_FILE, [
      'job' => 'archive_write',
      'status' => 'fail',
      'details' => ['error'=>$out['error'], 'duration_ms'=>$out['duration_ms']],
      'ip' => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '')),
      'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
      'src'=> $_GET['source'] ?? '',
    ]);
    jout($out, 200);
  }

  $seen_live = count($live);

  // Build 24h cutoff in Europe/London
  $now = now_london();
  $cut24 = (clone $now)->modify('-24 hours'); // strictly older than 24h

  // Normalise, keep only those older-than-24h
  $norm = [];
  foreach ($live as $x) {
    $n = norm_item($x);
    if (!$n) continue;
    $ldt = to_london($n['launchDate']);
    if (!$ldt) continue;
    if ($ldt < $cut24) $norm[] = $n;
  }
  $considered = count($norm);

  // Load existing ids to de-duplicate
  $existing = [];
  if (is_file($LINES_FILE)) {
    $fh = fopen($LINES_FILE, 'r');
    if ($fh) {
      while (($line = fgets($fh)) !== false) {
        $j = json_decode($line, true);
        if ($j && !empty($j['id'])) $existing[$j['id']] = true;
      }
      fclose($fh);
    }
  }

  // Append new ones
  $added = 0;
  foreach ($norm as $row) {
    $id = $row['id'];
    if (isset($existing[$id])) continue;
    if (write_line_append($LINES_FILE, $row)) {
      $existing[$id] = true;
      $added++;
    }
  }

  // Totals (lines in file after append)
  $total = 0;
  if (is_file($LINES_FILE)) {
    $total = count(@file($LINES_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
  }

  $out = [
    'ok'          => true,
    'seen_live'   => $seen_live,
    'considered'  => $considered,
    'added'       => $added,
    'total'       => $total,
    'duration_ms' => (int)round((microtime(true)-$t0)*1000),
  ];

  // ---------- append cron log line (never break the write) ----------
  try {
    log_cron($CRON_FILE, [
      'job'    => 'archive_write',
      'status' => $out['ok'] ? 'ok' : 'fail',
      'details'=> [
        'written'     => $out['added'],
        'total'       => $out['total'],
        'considered'  => $out['considered'],
        'seen_live'   => $out['seen_live'],
        'duration_ms' => $out['duration_ms'],
      ],
      'ip'  => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '')),
      'ua'  => $_SERVER['HTTP_USER_AGENT'] ?? '',
      'src' => $_GET['source'] ?? '',
    ]);
  } catch (Throwable $e) { /* ignore */ }

  jout($out, 200);
}

// ---------------------------------------------------------
// READ MODE: serve paginated archive JSON from jsonl file
// ---------------------------------------------------------

$page   = max(1, intval($_GET['page'] ?? 1));
$size   = max(1, min(200, intval($_GET['page_size'] ?? 50)));
$search = trim($_GET['search'] ?? '');
$since  = trim($_GET['since']  ?? '');
$until  = trim($_GET['until']  ?? '');
$includeYesterday = isset($_GET['include_yesterday']) && $_GET['include_yesterday'] == '1';

// If file missing → empty
if (!file_exists($LINES_FILE)) {
  jout([
    'meta'=>['total'=>0,'page'=>$page,'page_size'=>$size,'has_next'=>false,'has_prev'=>false],
    'items'=>[]
  ]);
}

// Read + sort newest-first
$items = read_lines_file($LINES_FILE);
usort($items, function($a,$b){
  $ta = strtotime($a['launchDate'] ?? '1970-01-01T00:00:00Z');
  $tb = strtotime($b['launchDate'] ?? '1970-01-01T00:00:00Z');
  return $tb <=> $ta;
});

// Base archive rule (Europe/London)
// Default: show only items earlier than "yesterday 00:00"
// If include_yesterday=1: show items earlier than "today 00:00" (includes yesterday group)
$now            = now_london();
$todayStart     = (clone $now)->setTime(0,0,0);
$yesterdayStart = (clone $todayStart)->modify('-1 day');
$cutoff         = $includeYesterday ? $todayStart : $yesterdayStart;

$items = array_values(array_filter($items, function($x) use ($cutoff){
  $ld = $x['launchDate'] ?? null;
  if (!$ld) return false;
  $t = to_london($ld);
  if (!$t) return false;
  return $t < $cutoff;
}));

// Optional search
if ($search !== '') {
  $q = mb_strtolower($search);
  $items = array_values(array_filter($items, function($x) use ($q){
    $hay = mb_strtolower(($x['shortName'] ?? '') . ' ' . ($x['title'] ?? '') . ' ' . ($x['website'] ?? ''));
    return mb_strpos($hay, $q) !== false;
  }));
}

// Optional date filters
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

jout([
  'meta' => [
    'total'    => $total,
    'page'     => $page,
    'page_size'=> $size,
    'has_next' => ($start + $size) < $total,
    'has_prev' => $page > 1
  ],
  'items' => $slice
]);