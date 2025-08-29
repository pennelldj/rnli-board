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

header('Content-Type: application/json; charset=utf-8');

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

// Read all lines (OK for tens of thousands; can be indexed later)
$lines = file($linesFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$items = [];
foreach ($lines as $line) {
  $o = json_decode($line, true);
  if ($o) $items[] = $o;
}

// Sort newest-first by launchDate
usort($items, function($a,$b){
  $ta = strtotime($a['launchDate'] ?? '1970-01-01T00:00:00Z');
  $tb = strtotime($b['launchDate'] ?? '1970-01-01T00:00:00Z');
  return $tb <=> $ta;
});

// ------------------------------------------------------------------
// Base archive rule: ONLY items older than "yesterday 00:00" (Europe/London)
// ------------------------------------------------------------------
try {
  $tz = new DateTimeZone('Europe/London');
} catch (Exception $e) {
  $tz = new DateTimeZone('UTC');
}
$now          = new DateTime('now', $tz);
$todayStart   = (clone $now)->setTime(0,0,0);
$yesterdayStart = (clone $todayStart)->modify('-1 day');

// Archive cutoff logic: default = "earlier than yesterday 00:00"
// If ?include_yesterday=1 is passed, then cutoff = today 00:00
$includeYesterday = isset($_GET['include_yesterday']) && $_GET['include_yesterday'] == '1';
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
    $hay = mb_strtolower(($x['shortName'] ?? '') . ' ' . ($x['title'] ?? '') . ' ' . ($x['website'] ?? ''));
    return mb_strpos($hay, $q) !== false;
  }));
}

// Optional since/until filters (applied after the base rule)
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