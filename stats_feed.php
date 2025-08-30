<?php
// stats_feed.php — reads data/launches.jsonl and returns aggregate stats
// This version never hard-crashes: it converts PHP warnings to exceptions
// and always returns JSON with ok:false + error details instead of 500s.

header('Content-Type: application/json; charset=utf-8');

// Convert notices/warnings into exceptions so we can JSON them
set_error_handler(function($severity, $message, $file, $line){
  if (!(error_reporting() & $severity)) return false;
  throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
  $dataDir = __DIR__ . '/data';
  $file = $dataDir . '/launches.jsonl';

  // Basic health checks
  if (!is_dir($dataDir)) {
    throw new RuntimeException("Data directory not found: $dataDir");
  }
  if (!file_exists($file)) {
    echo json_encode(['ok'=>false,'error'=>'archive empty','meta'=>['count'=>0],'stats'=>new stdClass()]);
    exit;
  }
  if (!is_readable($file)) {
    throw new RuntimeException("File not readable: $file (permissions)");
  }

  // Read file safely
  $items = [];
  $fh = fopen($file, 'r');
  if (!$fh) throw new RuntimeException("Unable to open: $file");
  while (($line = fgets($fh)) !== false) {
    $o = json_decode($line, true);
    if (!$o || empty($o['launchDate'])) continue;
    $ts = strtotime($o['launchDate']);
    if ($ts === false) continue;
    $o['_ts'] = $ts;
    $items[] = $o;
  }
  fclose($fh);

  $total = count($items);
  if ($total === 0) {
    echo json_encode(['ok'=>true,'meta'=>['count'=>0],'stats'=>[
      'by_region'=>['wales'=>0,'scotland'=>0,'england'=>0,'ireland'=>0,'other'=>0],
      'by_class'=>new stdClass(),
      'top_stations'=>new stdClass(),
      'top_classes'=>new stdClass(),
      'by_hour'=>array_fill(0,24,0),
      'last_7_days'=>[]
    ]]);
    exit;
  }

  // Helpers
  $dkey = function($ts){ return gmdate('Y-m-d', $ts); };
  $hour = function($ts){ return intval(gmdate('G', $ts)); };

  // Sort
  usort($items, fn($a,$b)=>$a['_ts']<=>$b['_ts']);
  $firstTs = $items[0]['_ts']; $lastTs = end($items)['_ts'];

  // Buckets
  $byRegion = ['wales'=>0,'scotland'=>0,'england'=>0,'ireland'=>0,'other'=>0];
  $byClass = [];
  $byStation = [];
  $byDay = [];
  $byHour = array_fill(0,24,0);

  // Lightweight region keywords (server-only; client uses regions.js)
  $regionWords = [
    'wales'    => ['wales','pembrokeshire','conwy','gwynedd','swansea','porthcawl','mumbles','tenby','anglesey','holyhead','bridgend'],
    'scotland' => ['scotland','shetland','orkney','largs','portree','skye','isle of skye','argyll','grampian','inverness','aberdeen'],
    'england'  => ['england','cornwall','devon','dorset','kent','essex','sussex','norfolk','yorkshire','lancashire','merseyside','suffolk','hants','hampshire'],
    'ireland'  => ['ireland','dublin','cork','galway','dún laoghaire','dun laoghaire','belfast','waterford']
  ];

  foreach ($items as $it) {
    $byDay[$dkey($it['_ts'])] = ($byDay[$dkey($it['_ts'])] ?? 0) + 1;
    $byHour[$hour($it['_ts'])]++;

    // Region (best-effort mirror)
    $hay = strtolower(($it['shortName']??'').' '.($it['title']??'').' '.($it['website']??''));
    $r = 'other';
    foreach ($regionWords as $key=>$words){
      foreach ($words as $w){ if (strpos($hay,$w)!==false){ $r=$key; break 2; } }
    }
    $byRegion[$r]++;

    // Boat class (mirror your boats.js patterns)
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
      elseif (preg_match('/^47-\d+$/',$op)) $class='Tyne class All-Weather';
      elseif (preg_match('/^52-\d+$/',$op)) $class='Arun class All-Weather';
      elseif (preg_match('/^44-\d+$/',$op)) $class='Waveney class All-Weather';
    }
    $byClass[$class ?: 'Unknown'] = ($byClass[$class ?: 'Unknown'] ?? 0) + 1;

    $st = trim($it['shortName'] ?? 'Unknown');
    $byStation[$st] = ($byStation[$st] ?? 0) + 1;
  }

  // last 7 days
  $days = [];
  $today = strtotime(gmdate('Y-m-d').'T00:00:00Z');
  for($i=6;$i>=0;$i--){
    $k = gmdate('Y-m-d', $today - $i*86400);
    $days[] = ['date'=>$k, 'count'=>intval($byDay[$k] ?? 0)];
  }

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
  ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(200); // return JSON rather than a 500
  echo json_encode([
    'ok' => false,
    'error' => $e->getMessage()
  ]);
}