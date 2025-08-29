<?php
/**
 * archive_feed.php (cache-first)
 *
 * WRITE MODE:
 *   ?write=1
 *     - Prefer local cache: ./data/last_live.json (written by proxy.php)
 *     - Else try proxy.php?url=RNLI
 *     - Else try direct RNLI
 *     - Merge (dedupe) into ./data/archive.json
 *
 * READ MODE:
 *   ?page=1&per_page=50&region=all&include_yesterday=0
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');

const API_URL      = 'https://services.rnli.org/api/launches?numberOfShouts=100';
const DIR_DATA     = __DIR__ . '/data';
const PATH_ARCHIVE = DIR_DATA . '/archive.json';
const PATH_CACHE   = DIR_DATA . '/last_live.json';
const PATH_LOCK    = __DIR__ . '/archive_writer.lock';
const UA           = 'RNLI-Archive/1.1 (+shout.stiwdio.com)';

$write = isset($_GET['write']) && $_GET['write']=='1';

try {
  if ($write) {
    $res = write_archive();
    echo json_encode(['ok'=>true] + $res);
  } else {
    echo json_encode(read_archive());
  }
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}

/* ----------------- WRITE ----------------- */
function write_archive(): array {
  if (!is_dir(DIR_DATA)) @mkdir(DIR_DATA, 0775, true);
  if (!file_exists(PATH_ARCHIVE)) file_put_contents(PATH_ARCHIVE, '[]');

  // Single-run lock
  $lock = @fopen(PATH_LOCK,'c');
  if (!$lock || !@flock($lock, LOCK_EX|LOCK_NB)) {
    return ['added'=>0,'total'=>archive_count(),'note'=>'Locked'];
  }

  // 1) Try cache first (fresh within 3 hours)
  $raw = null; $source = 'cache';
  if (is_readable(PATH_CACHE)) {
    $age = time() - filemtime(PATH_CACHE);
    if ($age <= 3*3600) {
      $raw = json_decode(file_get_contents(PATH_CACHE), true);
    }
  }

  // 2) If no cache, try local proxy
  if (!is_array($raw)) {
    $source = 'proxy';
    $host   = $_SERVER['HTTP_HOST'] ?? 'shout.stiwdio.com';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https://' : 'http://';
    $proxy  = $scheme . $host . '/proxy.php?url=' . rawurlencode(API_URL);
    try { $raw = fetch_json($proxy); } catch(Throwable $e) { $raw = null; }
  }

  // 3) If proxy fails, try direct (may 400 from RNLI)
  if (!is_array($raw)) {
    $source = 'direct';
    try { $raw = fetch_json(API_URL); } catch(Throwable $e) { $raw = null; }
  }

  if (!is_array($raw)) {
    throw new RuntimeException('No data from cache/proxy/direct');
  }

  $incoming = normalize($raw);

  // Load existing + index
  $existing = json_decode(@file_get_contents(PATH_ARCHIVE) ?: '[]', true);
  if (!is_array($existing)) $existing = [];
  $seen = [];
  foreach ($existing as $r) {
    $id = row_id($r);
    if ($id) $seen[$id] = true;
  }

  $added = 0;
  foreach ($incoming as $r) {
    $id = row_id($r);
    if (!$id || isset($seen[$id])) continue;
    $existing[] = $r;
    $seen[$id]  = true;
    $added++;
  }

  usort($existing, fn($a,$b)=>strcmp($b['time']??'', $a['time']??''));

  $tmp = PATH_ARCHIVE.'.tmp';
  file_put_contents($tmp, json_encode($existing, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
  @rename($tmp, PATH_ARCHIVE);

  @flock($lock, LOCK_UN); @fclose($lock);
  return ['added'=>$added, 'total'=>count($existing), 'source'=>$source];
}

/* ----------------- READ ------------------ */
function read_archive(): array {
  $page    = max(1, (int)($_GET['page'] ?? 1));
  $per     = min(200, max(1, (int)($_GET['per_page'] ?? 50)));
  $region  = strtolower(trim((string)($_GET['region'] ?? 'all')));
  $incYest = isset($_GET['include_yesterday']) && (int)$_GET['include_yesterday']===1;

  $all = json_decode(@file_get_contents(PATH_ARCHIVE) ?: '[]', true);
  if (!is_array($all)) $all = [];

  if ($region && $region !== 'all') {
    $all = array_values(array_filter($all, fn($r)=>region_match($r, $region)));
  }

  $now = new DateTime('now', new DateTimeZone('Europe/London'));
  $today = $now->format('Y-m-d');
  $yest  = $now->modify('-1 day')->format('Y-m-d');

  $all = array_values(array_filter($all, function($r) use($today,$yest,$incYest){
    $t = $r['time'] ?? '';
    if (!$t) return false;
    $d = substr($t,0,10);
    if ($d === $today) return false;
    if (!$incYest && $d === $yest) return false;
    return true;
  }));

  usort($all, fn($a,$b)=>strcmp($b['time']??'', $a['time']??''));

  $total = count($all);
  $items = array_slice($all, ($page-1)*$per, $per);

  return ['page'=>$page, 'per_page'=>$per, 'total'=>$total, 'items'=>$items];
}

/* --------------- Helpers ----------------- */
function fetch_json(string $url) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 25,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTPHEADER     => ['User-Agent: '.UA],
  ]);
  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);

  if ($resp === false || $code !== 200) {
    throw new RuntimeException("Upstream $code: $err");
  }
  $j = json_decode($resp, true);
  if (!is_array($j)) throw new RuntimeException('Bad JSON');
  return $j;
}

function normalize(array $rows): array {
  return array_values(array_map(function($x){
    $id  = $x['id'] ?? ($x['cOACS'] ?? null);
    $station = $x['shortName'] ?? 'Unknown';
    $time    = $x['launchDate'] ?? null;
    $title   = $x['title'] ?? '';
    $website = $x['website'] ?? '';
    $lifeboat= $x['lifeboat_IdNo'] ?? '';

    $location = '';
    if ($title && strpos($title, ',') !== false) {
      $parts = explode(',', $title);
      array_shift($parts);
      $location = trim(implode(',', $parts));
    }

    return [
      'id'       => $id,
      'station'  => $station,
      'time'     => $time,
      'title'    => $title,
      'location' => $location,
      'website'  => $website,
      'lifeboat' => $lifeboat,
    ];
  }, $rows));
}

function row_id(array $r): ?string {
  if (!empty($r['id'])) return (string)$r['id'];
  if (!empty($r['cOACS'])) return (string)$r['cOACS'];
  return null;
}

function region_match(array $r, string $region): bool {
  $s = strtolower(trim(($r['station']??'').' '.($r['location']??'').' '.($r['title']??'')));
  $areas = [
    'wales' => ['wales','anglesey','ynys môn','gwynedd','conwy','denbighshire','flintshire','wrexham','powys',
      'ceredigion','pembrokeshire','carmarthenshire','swansea','neath port talbot','bridgend',
      'vale of glamorgan','cardiff','rhondda','cynon','taf','merthyr tydfil','caerphilly',
      'blaenau gwent','torfaen','monmouthshire','newport','barry','penarth','mumbles','tenby','barmouth','burry port','pembrey'],
    'england' => ['england','cornwall','devon','dorset','somerset','hampshire','isle of wight','kent','sussex',
      'essex','norfolk','suffolk','lincolnshire','yorkshire','northumberland','tyne and wear','cumbria','lancashire',
      'merseyside','cheshire','durham','berkshire','buckinghamshire','oxfordshire','cambridgeshire','gloucestershire',
      'herefordshire','shropshire','worcestershire','warwickshire','leicestershire','rutland','derbyshire','nottinghamshire',
      'staffordshire','west midlands','greater manchester','west yorkshire','south yorkshire','east riding',
      'bristol','brighton','portsmouth','plymouth','lyme regis','weymouth'],
    'scotland' => ['scotland','highland','moray','aberdeenshire','angus','fife','perth and kinross','dundee','edinburgh','glasgow',
      'argyll','bute','western isles','orkney','shetland','ayrshire'],
    'ireland'  => ['ireland','northern ireland','antrim','derry','down','armagh','tyrone','fermanagh',
      'donegal','sligo','mayo','galway','clare','limerick','kerry','cork','waterford','wexford','dublin','wicklow','louth','meath'],
    'channel-islands' => ['jersey','guernsey','alderney','sark','herm','channel islands'],
  ];
  if (!isset($areas[$region])) return true;
  foreach ($areas[$region] as $k) if (strpos($s, $k)!==false) return true;
  return false;
}

function archive_count(): int {
  $j = json_decode(@file_get_contents(PATH_ARCHIVE) ?: '[]', true);
  return is_array($j) ? count($j) : 0;
}