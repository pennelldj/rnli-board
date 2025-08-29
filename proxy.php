<?php
// proxy.php — CORS bypass + archiver tee
// Place next to index.html. Requires a writable ./data folder.
//
// Writes new items to data/launches.jsonl (one JSON per line) and
// de-dup set to data/seen_ids.json. File-locked for safety.

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$allowed_hosts = ['services.rnli.org'];
if (!isset($_GET['url'])) {
  http_response_code(400);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['error' => 'Missing url']); exit;
}

$url = $_GET['url'];
$parts = parse_url($url);
if (!$parts || !isset($parts['host']) || !in_array($parts['host'], $allowed_hosts, true)) {
  http_response_code(400);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['error' => 'Host not allowed']); exit;
}

// fetch upstream
$ch = curl_init();
curl_setopt_array($ch, [
  CURLOPT_URL => $url,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT => 25,
  CURLOPT_CONNECTTIMEOUT => 10,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_HTTPHEADER => [
    'User-Agent: RNLI-Board/1.3',
    'Accept: application/json',
  ],
]);
$resp = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Save successful responses into cache
if ($http_code === 200 && $resp !== false) {
    $dataDir = __DIR__ . '/data';
    if (!is_dir($dataDir)) @mkdir($dataDir, 0775, true);
    @file_put_contents($dataDir . '/last_live.json', $resp);
}

http_response_code($http_code);
echo $resp;

// ---- Archive tee (best-effort; runs after echo) ----
if ($resp !== false && $http_code >= 200 && $http_code < 300) {
  $dataDir = __DIR__ . '/data';
  $linesFile = $dataDir . '/launches.jsonl';
  $seenFile  = $dataDir . '/seen_ids.json';

  if (!is_dir($dataDir)) { @mkdir($dataDir, 0775, true); }

  $json = json_decode($resp, true);
  if (!is_array($json)) { return; }

  // Load seen set
  $seen = [];
  if (file_exists($seenFile)) {
    $raw = @file_get_contents($seenFile);
    if ($raw !== false) { $seen = json_decode($raw, true) ?: []; }
  }

  // Open files with lock
  $lfh = @fopen($linesFile, 'a');
  $sok = @fopen($seenFile, 'c+');
  if (!$lfh || !$sok) { if ($lfh) fclose($lfh); if ($sok) fclose($sok); return; }

  // Exclusive lock seen file so we can update atomically
  if (flock($sok, LOCK_EX)) {
    // re-read seen inside lock to avoid races
    $curr = stream_get_contents($sok);
    if ($curr !== false && strlen($curr)) {
      $seen = json_decode($curr, true) ?: $seen;
    }
    $newSeen = $seen;

    foreach ($json as $x) {
      $id = isset($x['id']) ? $x['id'] : (isset($x['cOACS']) ? $x['cOACS'] : null);
      if (!$id) continue;
      if (isset($newSeen[$id])) continue;

      // Minimal normalized record for archive (keeps original too)
      $norm = [
        'id'         => $id,
        'shortName'  => $x['shortName'] ?? '',
        'launchDate' => $x['launchDate'] ?? null,
        'title'      => $x['title'] ?? '',
        'website'    => $x['website'] ?? '',
        'lifeboat_IdNo' => $x['lifeboat_IdNo'] ?? '',
        '_raw'       => $x
      ];

      // Append as JSONL line
      fwrite($lfh, json_encode($norm, JSON_UNESCAPED_SLASHES) . "\n");
      $newSeen[$id] = 1;
    }

    // Truncate & rewrite seen set
    ftruncate($sok, 0);
    rewind($sok);
    fwrite($sok, json_encode($newSeen));

    fflush($sok);
    flock($sok, LOCK_UN);
  }
  fclose($sok);
  fclose($lfh);
}