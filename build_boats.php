<?php
// build_boats.php — Build boats.json mapping OperationalNumber -> Class
// Primary source: RNLI Lifeboat Fleet feature service (REST). Fallbacks: alt item + KMZ + heuristic.
// Docs / dataset landing pages:
// - RNLI Open Data home: https://data-rnli.opendata.arcgis.com/
// - RNLI Lifeboat Fleet dataset: https://hub.arcgis.com/datasets/RNLI::rnli-lifeboat-fleet/about

header('Content-Type: text/plain; charset=utf-8');

function http_get($url, $timeout=25){
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => $timeout,
    CURLOPT_HTTPHEADER => ['User-Agent: RNLI-Board/1.2 (+shout.stiwdio.com)'],
  ]);
  $body = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);
  if ($body === false || $code >= 400) return [null, "HTTP $code $err"];
  return [$body, null];
}

function try_feature_query(&$map){
  // Known RNLI org host + item id. Layer index is usually 0.
  $endpoints = [
    // services1 (RNLI org)
    'https://services1.arcgis.com/evM5NkxAYjTi6XPw/arcgis/rest/services/RNLI_Lifeboat_Fleet/FeatureServer/0/query',
    // Generic ArcGIS Online item (in case name changes)
    // 'https://services.arcgis.com/.../FeatureServer/0/query'  // (kept for future tweaks)
  ];
  $fieldCombos = [
    'OperationalNumber,ClassName',
    'OperationalNumber,Class',
    'op_number,ClassName',
    'OperationalNumber,Equipment_Class,ClassName',
    '*'
  ];
  foreach ($endpoints as $ep){
    foreach ($fieldCombos as $fields){
      $q = $ep . '?where=1%3D1'
           . '&returnGeometry=false'
           . '&f=json'
           . '&outFields=' . urlencode($fields)
           . '&resultRecordCount=10000';
      list($txt, $err) = http_get($q);
      if (!$txt) { echo "REST fail: $q :: $err\n"; continue; }
      $j = json_decode($txt, true);
      if (!$j || !isset($j['features'])) { echo "REST bad JSON at $q\n"; continue; }
      $countBefore = count($map);
      foreach ($j['features'] as $f){
        $p = isset($f['attributes']) ? $f['attributes'] : (isset($f['properties'])?$f['properties']:[]);
        $id  = null;
        foreach (['OperationalNumber','op_number','Op_No','Op_Number'] as $k){
          if (isset($p[$k]) && trim($p[$k]) !== '') { $id = trim($p[$k]); break; }
        }
        if (!$id) continue;
        $class = null;
        foreach (['ClassName','Equipment_Class','Class','class'] as $k){
          if (isset($p[$k]) && trim($p[$k]) !== '') { $class = trim($p[$k]); break; }
        }
        if (!$class) $class = '';
        $map[$id] = $class;
      }
      if (count($map) > $countBefore) {
        echo "REST ok: $ep (fields=$fields) -> +" . (count($map)-$countBefore) . "\n";
        return true;
      }
    }
  }
  return false;
}

function try_kmz_parse(&$map){
  // A legacy KMZ often exists in the RNLI org cache; field names vary but usually include OperationalNumber + Class
  $urls = [
    'https://services1.arcgis.com/evM5NkxAYjTi6XPw/arcgis/rest/services/RNLI_Lifeboat_Fleet/FeatureServer/replicafilescache/RNLI_Lifeboat_Fleet_-8878051551891829683.kmz'
  ];
  foreach ($urls as $u){
    list($bin, $err) = http_get($u, 35);
    if (!$bin) { echo "KMZ fail: $u :: $err\n"; continue; }

    $tmp = sys_get_temp_dir() . '/rnli_kmz_' . uniqid() . '.kmz';
    file_put_contents($tmp, $bin);
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) { echo "KMZ not zip\n"; @unlink($tmp); continue; }
    $kmlStr = null;
    for ($i=0; $i<$zip->numFiles; $i++){
      $name = $zip->getNameIndex($i);
      if (preg_match('~\.kml$~i', $name)) { $kmlStr = $zip->getFromIndex($i); break; }
    }
    $zip->close(); @unlink($tmp);
    if (!$kmlStr) { echo "KMZ no KML inside\n"; continue; }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($kmlStr);
    if (!$xml) { echo "KML parse error\n"; continue; }
    $xml->registerXPathNamespace('k', 'http://www.opengis.net/kml/2.2');
    $xml->registerXPathNamespace('gx','http://www.google.com/kml/ext/2.2');

    $placemarks = $xml->xpath('//k:Placemark');
    $added=0;
    foreach ($placemarks as $pm){
      $id=null; $class=null;
      // ExtendedData fields vary; try common keys
      foreach ($pm->xpath('.//k:ExtendedData/k:Data') as $d){
        $nameAttr = (string)$d['name'];
        $val = trim((string)$d->value);
        if (!$id && preg_match('~OperationalNumber|op_number|Op_No~i', $nameAttr)) $id = $val;
        if (!$class && preg_match('~Class(Name)?|Equipment_Class~i', $nameAttr)) $class = $val;
      }
      if ($id){
        if (!$class) $class = '';
        $map[$id] = $class;
        $added++;
      }
    }
    echo "KMZ parsed: +$added\n";
    if ($added>0) return true;
  }
  return false;
}

function class_from_id_heuristic($id){
  // Fallback when class is missing: infer from op number pattern.
  // Examples: D-755 => D-class ILB, B-929 => Atlantic (B-class), 13-## => Shannon, 17-## => Severn, 16-## => Tamar, 14-## => Trent, 12-## => Mersey, H-0# => Hovercraft, E-### => E-class
  if (preg_match('~^D-\d+~i', $id)) return 'D-class Inshore';
  if (preg_match('~^B-\d+~i', $id)) return 'Atlantic (B-class) Inshore';
  if (preg_match('~^E-\d+~i', $id)) return 'E-class (Thames) Inshore';
  if (preg_match('~^H-\d+~i', $id)) return 'Hovercraft';
  if (preg_match('~^13-\d+~', $id))  return 'Shannon class All-Weather';
  if (preg_match('~^17-\d+~', $id))  return 'Severn class All-Weather';
  if (preg_match('~^16-\d+~', $id))  return 'Tamar class All-Weather';
  if (preg_match('~^14-\d+~', $id))  return 'Trent class All-Weather';
  if (preg_match('~^12-\d+~', $id))  return 'Mersey class All-Weather';
  if (preg_match('~^XP-?\d+~i', $id))return 'XP-class';
  return '';
}

// -------------------- run --------------------
$map = [];

// 1) REST query (best)
$ok = try_feature_query($map);

// 2) KMZ fallback (legacy export)
if (!$ok) $ok = try_kmz_parse($map);

// 3) If still empty, fail gracefully
if (!$ok && !count($map)) {
  echo "ERROR: Could not fetch fleet via REST or KMZ.\n";
  exit;
}

// 4) Fill blanks with heuristic
$patched = 0;
foreach ($map as $id => $cls){
  if ($cls === '' || $cls === null) {
    $guess = class_from_id_heuristic($id);
    if ($guess !== '') { $map[$id] = $guess; $patched++; }
  }
}

// 5) Write boats.json
ksort($map, SORT_NATURAL|SORT_FLAG_CASE);
file_put_contents(__DIR__.'/boats.json', json_encode($map, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));

echo "Wrote boats.json: ".count($map)." boats\n";
if ($patched) echo "Filled missing classes by heuristic: $patched\n";

// sample output
$sample = array_slice($map, 0, 10, true);
foreach ($sample as $id => $cls) echo "$id => $cls\n";