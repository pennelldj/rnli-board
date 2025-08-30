<?php
// archive_feed.php — paginate archived RNLI launches from data/launches.jsonl
// This version HIDES "Today" and "Yesterday" so the archive is true history.
// Time boundaries use Europe/London so groupings match the live board.
//
// Query params:
//   page (default 1), page_size (default 50, max 200)
//   search  (optional; matches shortName/title/website; case-insensitive)
//   since   (optional; ISO or YYYY-MM-DD; applied AFTER the "older than yesterday" rule)
//   until   (optional; ISO or YYYY-MM-DD)
//   include_yesterday=1  → include Yesterday in archive
//   write=1              → fetch RNLI feed, persist >24h items to data/launches.jsonl

header('Content-Type: application/json; charset=utf-8');

// --- WRITE MODE: persist >24h items to data/launches.jsonl -------------------
if (isset($_GET['write']) && $_GET['write'] === '1') {
  $dataDir   = __DIR__ . '/data';
  $linesFile = $dataDir . '/launches.jsonl';
  if (!is_dir($dataDir)) { @mkdir($dataDir, 0775, true); }

  // Load existing keys (for dedupe)
  $existing = [];
  if (file_exists($linesFile)) {
    $fh = fopen($linesFile, 'r');
    if ($fh) {
      while (($line = fgets($fh)) !== false) {
        $o = json_decode($line, true);
        if (!$o) continue;
        $k = ($o['id'] ?? '') . '|' . ($o['cOACS'] ?? '') . '|' . substr(($o['launchDate'] ?? ''), 0, 16);
        $existing[$k] = true;
      }
      fclose($fh);
    }
  }

  // Helper: GET via cURL
  function http_get($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_CONNECTTIMEOUT => 6,
      CURLOPT_TIMEOUT => 12,
      CURLOPT_USERAGENT => 'ShoutArchiveBot/1.0 (+https://shout.stiwdio.com)',
      CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code >= 200 && $code < 300) ? $body : false;
  }

  // Fetch recent shouts (direct, then fallback to proxy)
  $limit   = max(25, min(500, intval($_GET['limit'] ?? 200)));
  $rnliUrl = 'https://services.rnli.org/api/launches?numberOfShouts=' . $limit;

  $json = http_get($rnliUrl);
  if ($json === false) {
    $proxyAbs = 'https://shout.stiwdio.com/proxy.php?url=' . urlencode($rnliUrl);
    $json = http_get($proxyAbs);
  }
  if ($json === false) {
    http_response_code(502);
    echo json_encode(['ok'=>false,'error'=>'fetch_failed']);
    exit;
  }

  $list = json_decode($json, true);
  if (!is_array($list)) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'bad_payload']);
    exit;
  }

  // Cutoff: items with launchDate <= now-24h (UTC) are eligible
  $nowUTC = new DateTimeImmutable('now', new DateTimeZone('UTC'));
  $cutoff = $nowUTC->sub(new DateInterval('PT24H'));

  $added = 0; $scanned = 0; $eligible = 0; $out = [];

  foreach ($list as $x) {
    $scanned++;
    $ldStr = $x['launchDate'] ?? null;
    if (!$ldStr) continue;
    try { $ld = new DateTimeImmutable($ldStr, new DateTimeZone('UTC')); }
    catch (Exception $e) { continue; }

    if ($ld <= $cutoff) {
      $eligible++;
      $key = ($x['id'] ?? '') . '|' . ($x['cOACS'] ?? '') . '|' . substr($ldStr, 0, 16);
      if (!isset($existing[$key])) {
        $obj = [
          'id'            => $x['id'] ?? null,
          'cOACS'         => $x['cOACS'] ?? null,
          'shortName'     => $x['shortName'] ?? ($x['stationName'] ?? ''),
          'launchDate'    => $x['launchDate'] ?? null,
          'title'         => $x['title'] ?? ($x['location'] ?? ''),
          'website'       => $x['website'] ?? '',
          'lifeboat_IdNo' => $x['lifeboat_IdNo'] ?? ''
        ];
        $out[] = json_encode($obj, JSON_UNESCAPED_SLASHES);
        $existing[$key] = true;
        $added++;
      }
    }
  }

  // Append with a lock
  if ($added > 0) {
    $lock = fopen($linesFile . '.lock', 'c+');
    if ($lock) {
      flock($lock, LOCK_EX);
      $fh = fopen($linesFile, 'a');
      if ($fh) { foreach ($out as $ln) fwrite($fh, $ln . "\n"); fclose($fh); }
      flock($lock, LOCK_UN);
      fclose($lock);
    }
  }

  echo json_encode([
    'ok' => true,
    'scanned' => $scanned,
    'eligible' => $eligible,
    'added' => $added,
    'total_estimate' => count($existing)
  ]);
  exit;
}
// --- END WRITE MODE ----------------------------------------------------------

// ------------------------------------------------------------------
// READER MODE — paginate and filter launches.jsonl
// ------------------------------------------------------------------

$dataDir   = __DIR__ . '/data';
$linesFile = $dataDir . '/launches.jsonl';

$page  = max(1, intval($_GET['page'] ?? 1));
$size  = max(1, min(200, intval($_GET['page_size'] ?? 50)));
$search = trim($_GET['search'] ?? '');
$since  = trim($_GET['since']  ?? '');
$until  = trim($_GET['until']  ?? '');

// If archive file doesn't exist yet, return empty set
if (!file_exists($linesFile)) {
  echo json_encode([
    'meta'=>['total'=>0,'page'=>$page,'page_size'=>$size,'has_next'=>false,'has_prev'=>false],
    'items'=>[]
  ]);
  exit;
}

// Read all lines
$lines = file($linesFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$items = [];
foreach ($lines as $line) {
  $o = json_decode($line, true);
  if ($o) $items[] = $o;
}

// Sort newest-first
usort($items, function($a,$b){
  $ta = strtotime($a['launchDate'] ?? '1970-01-01T00:00:00Z');
  $tb = strtotime($b['launchDate'] ?? '1970-01-01T00:00:00Z');
  return $tb <=> $ta;
});

// Base archive cutoff: items with launchDate <= now-24h (UTC)
$nowUTC = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$cutoff = $nowUTC->sub(new DateInterval('PT24H'));

$includeYesterday = isset($_GET['include_yesterday']) && $_GET['include_yesterday'] == '1';
// If include_yesterday=1, relax cutoff to "today 00:00 Europe/London"
if ($includeYesterday) {
  try { $tz = new DateTimeZone('Europe/London'); } catch (Exception $e) { $tz = new DateTimeZone('UTC'); }
  $todayStart   = (new DateTime('now', $tz))->setTime(0,0,0);
  $cutoff = DateTimeImmutable::createFromMutable($todayStart)->setTimezone(new DateTimeZone('UTC'));
}

$items = array_values(array_filter($items, function($x) use ($cutoff) {
  $ld = $x['launchDate'] ?? null;
  if (!$ld) return false;
  try { $t = new DateTimeImmutable($ld, new DateTimeZone('UTC')); }
  catch (Exception $e) { return false; }
  return $t <= $cutoff; // inclusive
}));

// Optional search
if ($search !== '') {
  $q = mb_strtolower($search);
  $items = array_values(array_filter($items, function($x) use ($q){
    $hay = mb_strtolower(($x['shortName'] ?? '') . ' ' . ($x['title'] ?? '') . ' ' . ($x['website'] ?? ''));
    return mb_strpos($hay, $q) !== false;
  }));
}

// Optional since/until
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