<?php
// stats_feed.php here — fast aggregates for RNLI archive (reads launches.jsonl)
header('Content-Type: application/json; charset=utf-8');

$dataDir = __DIR__ . '/data';
$file = $dataDir . '/launches.jsonl';
if (!file_exists($file)) {
  echo json_encode(['ok'=>false,'error'=>'archive empty']); exit;
}

$tz = new DateTimeZone('UTC');
$items = [];
$fh = fopen($file, 'r');
while (($line = fgets($fh)) !== false) {
  $o = json_decode($line, true);
  if (!$o || empty($o['launchDate'])) continue;
  $o['_ts'] = strtotime($o['launchDate']);
  if (!$o['_ts']) continue;
  $items[] = $o;
}
fclose($fh);
if (!$items) { echo json_encode(['ok'=>true,'meta'=>['count'=>0],'stats'=>[]]); exit; }

// helpers
function dkey($ts){ return gmdate('Y-m-d', $ts); }
function hour($ts){ return intval(gmdate('G', $ts)); }

$total = count($items);
usort($items, fn($a,$b)=>$a['_ts']<=>$b['_ts']);
$firstTs = $items[0]['_ts']; $lastTs = end($items)['_ts'];

$byRegion = ['wales'=>0,'scotland'=>0,'england'=>0,'ireland'=>0,'other'=>0];
$byClass = [];           // 'D-class Inshore' => n, 'Unknown' => n
$byStation = [];         // 'Padstow' => n
$byDay = [];             // 'YYYY-MM-DD' => n
$byHour = array_fill(0,24,0);

foreach ($items as $it) {
  $byDay[dkey($it['_ts'])] = ($byDay[dkey($it['_ts'])]??0)+1;

  $h = hour($it['_ts']); $byHour[$h]++;

  // region via detectRegion from regions.js (client), but here we infer simple:
  $hay = strtolower(($it['shortName']??'').' '.($it['title']??'').' '.($it['website']??''));
  $r = 'other';
  foreach (['wales','scotland','england','ireland'] as $rr) {
    // cheap server-side mirror of client logic using keywords file later if you want
    // for now a few anchors to not block you:
    $hits = [
      'wales'=>['wales','pembrokeshire','conwy','gwynedd','swansea','porthcawl','mumbles','tenby'],
      'scotland'=>['scotland','shetland','orkney','largs','portree','skye','argyll','grampian'],
      'england'=>['england','cornwall','devon','kent','suffolk','hants','essex','yorkshire','merseyside'],
      'ireland'=>['ireland','dublin','cork','galway','dún laoghaire','dun laoghaire','belfast']
    ][$rr];
    foreach ($hits as $w) { if (str_contains($hay,$w)) { $r=$rr; break 2; } }
  }
  $byRegion[$r]++;

  // class via simple pattern (mirror of boats.js)
  $op = strtoupper(trim($it['lifeboat_IdNo'] ?? ''));
  $class = null;
  if ($op !== '') {
    if (preg_match('/^D-\d+$/i',$op)) $class='D-class Inshore';
    elseif (preg_match('/^B{1,2}-\d+$/i',$op)) $class='Atlantic (B-class) Inshore';
    elseif (preg_match('/^E-\d+$/i',$op)) $class='E-class (Thames) Inshore';
    elseif (preg_match('/^H-\d+$/i',$op)) $class='Hovercraft';
    elseif (preg_match('/^(XP|X)-?\d+$/i',$op)) $class='XP-class';
    elseif (preg_match('/^13-\d+$/',$op)) $class='Shannon class All-Weather';
    elseif (preg_match('/^17-\d+$/',$op)) $class='Severn class All-Weather';
    elseif (preg_match('/^16-\d+$/',$op)) $class='Tamar class All-Weather';
    elseif (preg_match('/^14-\d+$/',$op)) $class='Trent class All-Weather';
    elseif (preg_match('/^12-\d+$/',$op)) $class='Mersey class All-Weather';
  }
  $byClass[$class ?: 'Unknown'] = ($byClass[$class ?: 'Unknown'] ?? 0) + 1;

  $st = trim($it['shortName'] ?? 'Unknown');
  $byStation[$st] = ($byStation[$st] ?? 0) + 1;
}

// last 7 days time series (UTC)
$days = [];
$today = strtotime(gmdate('Y-m-d').'T00:00:00Z');
for($i=6;$i>=0;$i--){
  $k = gmdate('Y-m-d', $today - $i*86400);
  $days[] = ['date'=>$k, 'count'=>intval($byDay[$k] ?? 0)];
}

// top N stations/classes
arsort($byStation); $topStations = array_slice($byStation, 0, 10, true);
arsort($byClass);   $topClasses  = array_slice($byClass, 0, 10, true);

echo json_encode([
  'ok'=>true,
  'meta'=>[
    'count'=>$total,
    'first'=>gmdate('c',$firstTs),
    'last'=>gmdate('c',$lastTs),
    'generated'=>gmdate('c')
  ],
  'stats'=>[
    'by_region'=>$byRegion,
    'by_class'=>$byClass,
    'top_stations'=>$topStations,
    'top_classes'=>$topClasses,
    'by_hour'=>$byHour,
    'last_7_days'=>$days
  ]
], JSON_UNESCAPED_SLASHES);