<?php
/**
 * archive_feed.php
 *
 * - WRITE MODE (cron / manual):
 *   GET ?write=1                     -> fetch RNLI feed, merge (dedupe) into data/archive.json
 *   Returns: {"ok":true,"added":N,"total":M}
 *
 * - READ MODE (archive.html):
 *   GET ?page=1&per_page=50&region=all&include_yesterday=0
 *   Returns: {"page":1,"per_page":50,"total":123,"items":[...]}
 *
 * Notes:
 * - Archive file: ./data/archive.json
 * - Dedupe by "id" (falls back to cOACS if id missing)
 * - Sort newest-first by time
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

// ---------- Config ----------
const API_URL        = 'https://services.rnli.org/api/launches?numberOfShouts=100';
const ARCHIVE_DIR    = __DIR__ . '/data';
const ARCHIVE_PATH   = ARCHIVE_DIR . '/archive.json';
const LOCK_PATH      = __DIR__ . '/archive_writer.lock';
const USER_AGENT     = 'RNLI-Archive/1.0 (+shout.stiwdio.com)';

// ---------- Router ----------
$writeMode = isset($_GET['write']) && $_GET['write'] == '1';
try {
  if ($writeMode) {
    $res = write_archive();
    echo json_encode(['ok' => true] + $res);
    exit;
  } else {
    echo json_encode(read_archive());
    exit;
  }
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  exit;
}

// ---------- Functions ----------

/**
 * WRITE MODE: fetch RNLI, merge into archive.json with de-dup, save atomically.
 */
function write_archive(): array {
  // Lock to prevent overlapping runs
  $lock = @fopen(LOCK_PATH, 'c');
  if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) {
    return ['ok'=>false, 'added'=>0, 'total'=>archive_count(), 'note'=>'Locked'];
  }

  // Ensure dir + file exist
  if (!is_dir(ARCHIVE_DIR)) @mkdir(ARCHIVE_DIR, 0775, true);
  if (!file_exists(ARCHIVE_PATH)) file_put_contents(ARCHIVE_PATH, '[]');

  // Load existing
  $existing = json_decode(@file_get_contents(ARCHIVE_PATH) ?: '[]', true);
  if (!is_array($existing)) $existing = [];

  // Build index of seen IDs
  $seen = [];
  foreach ($existing as $row) {
    $rid = row_id($row);
    if ($rid) $seen[$rid] = true;
  }

  // Fetch new data
  $raw = fetch_json(API_URL);
  $incoming = normalize($raw);

  // Merge unseen
  $added = 0;
  foreach ($incoming as $row) {
    $rid = row_id($row);
    if (!$rid) continue;
    if (isset($seen[$rid])) continue;
    $existing[] = $row;
    $seen[$rid] = true;
    $added++;
  }

  // Sort newest-first by time
  usort($existing, function($a, $b) {
    return strcmp($b['time'] ?? '', $a['time'] ?? '');
  });

  // Atomic save
  $tmp = ARCHIVE_PATH . '.tmp';
  file_put_contents($tmp, json_encode($existing, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  @rename($tmp, ARCHIVE_PATH);

  @flock($lock, LOCK_UN);
  @fclose($lock);

  return ['added' => $added, 'total' => count($existing)];
}

/**
 * READ MODE: paginate + filter archive.
 */
function read_archive(): array {
  $page     = max(1, (int)($_GET['page'] ?? 1));
  $perPage  = min(200, max(1, (int)($_GET['per_page'] ?? 50)));
  $region   = strtolower(trim((string)($_GET['region'] ?? 'all')));
  $incYest  = isset($_GET['include_yesterday']) && (int)$_GET['include_yesterday'] === 1;

  $all = json_decode(@file_get_contents(ARCHIVE_PATH) ?: '[]', true);
  if (!is_array($all)) $all = [];

  // Filter region
  if ($region && $region !== 'all') {
    $all = array_values(array_filter($all, function($r) use ($region) {
      return region_match($r, $region);
    }));
  }

  // Exclude today (and yesterday unless include_yesterday=1)
  $now      = new DateTime('now', new DateTimeZone('Europe/London'));
  $todayStr = $now->format('Y-m-d');
  $yestStr  = $now->modify('-1 day')->format('Y-m-d');

  $all = array_values(array_filter($all, function($r) use ($todayStr, $yestStr, $incYest) {
    $t = $r['time'] ?? null;
    if (!$t) return false;
    $d = substr($t, 0, 10); // YYYY-MM-DD
    if ($d === $todayStr) return false;          // drop today
    if (!$incYest && $d === $yestStr) return false; // drop yesterday unless opted in
    return true;
  }));

  // Ensure newest-first
  usort($all, function($a, $b) {
    return strcmp($b['time'] ?? '', $a['time'] ?? '');
  });

  // Pagination
  $total = count($all);
  $start = ($page - 1) * $perPage;
  $items = array_slice($all, $start, $perPage);

  return [
    'page'      => $page,
    'per_page'  => $perPage,
    'total'     => $total,
    'items'     => $items,
  ];
}

/**
 * Helpers
 */

function fetch_json(string $url) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTPHEADER     => ['User-Agent: ' . USER_AGENT],
  ]);
  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);
  if ($resp === false || $code !== 200) {
    throw new RuntimeException("Upstream $code: $err");
  }
  $data = json_decode($resp, true);
  if (!is_array($data)) throw new RuntimeException('Bad JSON from RNLI');
  return $data;
}

/**
 * Normalize RNLI row into archive schema.
 */
function normalize(array $rows): array {
  return array_values(array_map(function($x) {
    // Some RNLI rows use cOACS; keep both and set 'id'
    $id = $x['id']     ?? null;
    $co = $x['cOACS']  ?? null;
    if (!$id && $co) $id = $co;

    $station = $x['shortName'] ?? 'Unknown';
    $time    = $x['launchDate'] ?? null;
    $title   = $x['title'] ?? '';
    $website = $x['website'] ?? '';
    $lifeboat= $x['lifeboat_IdNo'] ?? '';

    // Try to tease out a location from the title (e.g., "Lyme Regis, Dorset")
    $location = '';
    if ($title && strpos($title, ',') !== false) {
      $parts = explode(',', $title);
      array_shift($parts); // drop station name
      $location = trim(implode(',', $parts));
    }

    return [
      'id'       => $id,
      'station'  => $station,
      'time'     => $time,       // ISO 8601
      'title'    => $title,
      'location' => $location,
      'website'  => $website,
      'lifeboat' => $lifeboat,
    ];
  }, $rows));
}

/**
 * Consistent ID getter for dedupe.
 */
function row_id(array $r): ?string {
  if (!empty($r['id'])) return (string)$r['id'];
  if (!empty($r['cOACS'])) return (string)$r['cOACS'];
  return null;
}

/**
 * Match by UK nation regions, based on station + location + title text.
 */
function region_match(array $r, string $region): bool {
  $s = strtolower(trim(
    ($r['station'] ?? '') . ' ' .
    ($r['location'] ?? '') . ' ' .
    ($r['title'] ?? '')
  ));

  $map = [
    'wales' => [
      'wales','anglesey','ynys môn','gwynedd','conwy','denbighshire','flintshire','wrexham','powys',
      'ceredigion','pembrokeshire','carmarthenshire','swansea','neath port talbot','bridgend',
      'vale of glamorgan','cardiff','rhondda','cynon','taf','merthyr tydfil','caerphilly',
      'blaenau gwent','torfaen','monmouthshire','newport','barry','penarth','mumbles','tenby','barmouth','burry port','pembrey'
    ],
    'england' => [
      'england','cornwall','devon','dorset','somerset','hampshire','isle of wight','kent','sussex',
      'essex','norfolk','suffolk','lincolnshire','yorkshire','northumberland','tyne and wear',
      'cumbria','lancashire','merseyside','cheshire','durham','berkshire','buckinghamshire',
      'oxfordshire','cambridgeshire','gloucestershire','herefordshire','shropshire','worcestershire',
      'warwickshire','leicestershire','rutland','derbyshire','nottinghamshire','staffordshire',
      'west midlands','greater manchester','west yorkshire','south yorkshire','east riding',
      'bristol','brighton','portsmouth','plymouth','lyme regis','weymouth','rnli city of london' // plus common station names
    ],
    'scotland' => [
      'scotland','highland','moray','aberdeenshire','angus','fife','perth and kinross','dundee',
      'edinburgh','lothian','glasgow','argyll','bute','western isles','na h-eileanan siar','orkney','shetland','ayrshire'
    ],
    'ireland' => [
      'ireland','northern ireland','county antrim','derry','londonderry','down','armagh','tyrone','fermanagh',
      'donegal','sligo','mayo','galway','clare','limerick','kerry','cork','waterford','wexford','dublin','wicklow','louth','meath'
    ],
    'channel-islands' => [
      'channel islands','jersey','guernsey','alderney','sark','herm'
    ],
  ];

  if (!isset($map[$region])) return true; // unknown region behaves like 'all'
  foreach ($map[$region] as $w) {
    if (strpos($s, strtolower($w)) !== false) return true;
  }
  return false;
}

/**
 * Count helper for quick lock reply.
 */
function archive_count(): int {
  $j = json_decode(@file_get_contents(ARCHIVE_PATH) ?: '[]', true);
  return is_array($j) ? count($j) : 0;
}