<?php
// build_stations_geo.php Fetch RNLI station coords and write stations_geo.json + stations.json
// Source dataset: RNLI Lifeboat Station Locations (238 stations, UK & ROI)
$targets = [
  // ArcGIS Hub / Open Data common download patterns (GeoJSON)
  'https://hub.arcgis.com/api/v3/datasets/7dad2e58254345c08dfde737ec348166_0/downloads/data?format=geojson&spatialRefId=4326',
  'https://opendata.arcgis.com/api/v3/datasets/7dad2e58254345c08dfde737ec348166_0/downloads/data?format=geojson&spatialRefId=4326',
  'https://hub.arcgis.com/datasets/7dad2e58254345c08dfde737ec348166_0.geojson',
  'https://opendata.arcgis.com/datasets/7dad2e58254345c08dfde737ec348166_0.geojson',
];

function fetch_url($url) {
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 20);
  curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: RNLI-Board/1.0 (+shout.stiwdio.com)']);
  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);
  if ($resp === false || $code >= 400) return [null, "HTTP $code $err"];
  return [$resp, null];
}

function pick_name($props) {
  $candidates = ['short_name','ShortName','Short_Name','Station','Station_Name','StationName','Name','name','Title','title'];
  foreach ($candidates as $k) {
    if (isset($props[$k]) && trim($props[$k]) !== '') return trim($props[$k]);
  }
  return null;
}

$geojson = null; $used = null; $errsum = [];
foreach ($targets as $u) {
  list($txt, $e) = fetch_url($u);
  if ($txt) {
    $j = json_decode($txt, true);
    if ($j && isset($j['type']) && $j['type']==='FeatureCollection' && isset($j['features'])) {
      $geojson = $j; $used = $u; break;
    } else {
      $errsum[] = "Bad JSON from $u";
    }
  } else {
    $errsum[] = "Failed $u: $e";
  }
}

header('Content-Type: text/plain; charset=utf-8');

if (!$geojson) {
  echo "ERROR: Could not download RNLI station dataset.\n";
  echo implode("\n", $errsum);
  exit;
}

$map = [];   // "Station Name" => [lat, lon]
$names = [];

foreach ($geojson['features'] as $f) {
  if (!isset($f['geometry']['type']) || $f['geometry']['type'] !== 'Point') continue;
  $coords = $f['geometry']['coordinates']; // [lon, lat]
  if (!is_array($coords) || count($coords) < 2) continue;
  $lat = (float)$coords[1];
  $lon = (float)$coords[0];

  $props = isset($f['properties']) && is_array($f['properties']) ? $f['properties'] : [];
  $name  = pick_name($props);
  if (!$name) continue;

  // Deduplicate by name; prefer last (should all match)
  $map[$name] = [round($lat, 6), round($lon, 6)];
  $names[$name] = true;
}

// Write files
$geo_path = __DIR__ . '/stations_geo.json';
$list_path = __DIR__ . '/stations.json';
ksort($map, SORT_NATURAL|SORT_FLAG_CASE);
$names_list = array_keys($names);
sort($names_list, SORT_NATURAL|SORT_FLAG_CASE);

file_put_contents($geo_path, json_encode($map, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
file_put_contents($list_path, json_encode($names_list, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));

// Report
echo "OK: Downloaded from:\n$used\n\n";
echo "Wrote stations_geo.json (" . count($map) . " stations)\n";
echo "Wrote stations.json (" . count($names_list) . " names)\n\n";
echo "Sample:\n";
$sample = array_slice($map, 0, 5, true);
foreach ($sample as $n => $ll) { echo "  \"$n\": [{$ll[0]}, {$ll[1]}]\n"; }