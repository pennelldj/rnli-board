<?php
/**
 * proxy.php — RNLI JSON proxy with cache + archive tee
 *
 * Usage (choose ONE):
 *   /proxy.php?endpoint=launches&n=100              (recommended; avoids WAF)
 *   /proxy.php?u=BASE64URL                          (base64 of full URL)
 *   /proxy.php?url=https%3A%2F%2Fservices.rnli.org%2Fapi%2Flaunches%3FnumberOfShouts%3D25
 *
 * Behavior:
 * - Allowlist: services.rnli.org
 * - On 200 from upstream: writes ./data/last_live.json and appends ./data/launches.jsonl (dedup via seen_ids.json)
 * - On upstream error: if cache exists, returns cached JSON with 200 (prevents blank UI)
 * - Else: returns JSON error with upstream status code
 */

declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Content-Type: application/json; charset=utf-8');

$ALLOWED_HOSTS = ['services.rnli.org'];
$UA            = 'RNLI-Board/1.3 (+shout.stiwdio.com)';

$DATA_DIR      = __DIR__ . '/data';
$CACHE_PATH    = $DATA_DIR . '/last_live.json';
$TEE_PATH      = $DATA_DIR . '/launches.jsonl';
$SEEN_PATH     = $DATA_DIR . '/seen_ids.json';

/* ---------------------- Resolve target URL ---------------------- */
// 1) Internal endpoint (safest: no full URL in query)
if (isset($_GET['endpoint']) && $_GET['endpoint'] === 'launches') {
  $n   = isset($_GET['n']) ? max(1, (int)$_GET['n']) : 25;
  $url = 'https://services.rnli.org/api/launches?numberOfShouts=' . $n;

// 2) base64 / base64url parameter
} elseif (isset($_GET['u'])) {
  $u   = strtr(trim((string)$_GET['u']), '-_', '+/'); // base64url -> base64
  $url = base64_decode($u, true);
  if ($url === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Bad base64 in u']);
    exit;
  }

// 3) plain or encoded ?url=
} elseif (isset($_GET['url'])) {
  $url = (string)$_GET['url'];
  if (strpos($url, '%') !== false) $url = rawurldecode($url);
  $url = trim($url);
} else {
  http_response_code(400);
  echo json_encode(['error' => 'Missing url/u/endpoint']);
  exit;
}

// allowlist check
$parts = @parse_url($url);
if (!$parts || empty($parts['host']) || !in_array($parts['host'], $ALLOWED_HOSTS, true)) {
  http_response_code(400);
  echo json_encode(['error' => 'Host not allowed']);
  exit;
}

/* ---------------------- Fetch upstream ---------------------- */
$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_TIMEOUT        => 25,
  CURLOPT_CONNECTTIMEOUT => 10,
  CURLOPT_HTTPHEADER     => [
    'User-Agent: ' . $UA,
    'Accept: application/json',
  ],
]);
$resp      = curl_exec($ch);
$http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err       = curl_error($ch);
curl_close($ch);

/* ---------------------- On success: cache + tee ---------------------- */
if ($http_code === 200 && $resp !== false) {
  if (!is_dir($DATA_DIR)) @mkdir($DATA_DIR, 0775, true);
  // cache last good JSON
  @file_put_contents($CACHE_PATH, $resp);

  // tee to JSONL with dedup via seen_ids.json (best-effort)
  $json = json_decode($resp, true);
  if (is_array($json)) {
    // load seen set
    $seen = [];
    if (file_exists($SEEN_PATH)) {
      $raw = @file_get_contents($SEEN_PATH);
      if ($raw !== false) $seen = json_decode($raw, true) ?: [];
    }
    $lfh = @fopen($TEE_PATH, 'a');
    $sok = @fopen($SEEN_PATH, 'c+');
    if ($lfh && $sok) {
      if (flock($sok, LOCK_EX)) {
        $curr = stream_get_contents($sok);
        if ($curr !== false && strlen($curr)) $seen = json_decode($curr, true) ?: $seen;
        $newSeen = $seen;

        foreach ($json as $x) {
          $id = $x['id'] ?? ($x['cOACS'] ?? null);
          if (!$id || isset($newSeen[$id])) continue;

          $norm = [
            'id'            => $id,
            'shortName'     => $x['shortName']     ?? '',
            'launchDate'    => $x['launchDate']    ?? null,
            'title'         => $x['title']         ?? '',
            'website'       => $x['website']       ?? '',
            'lifeboat_IdNo' => $x['lifeboat_IdNo'] ?? '',
            '_raw'          => $x,
          ];
          fwrite($lfh, json_encode($norm, JSON_UNESCAPED_SLASHES) . "\n");
          $newSeen[$id] = 1;
        }

        // rewrite seen set atomically
        ftruncate($sok, 0);
        rewind($sok);
        fwrite($sok, json_encode($newSeen));
        fflush($sok);
        flock($sok, LOCK_UN);
      }
      fclose($sok);
      fclose($lfh);
    }
  }

  // mirror 200 and return upstream JSON
  http_response_code(200);
  echo $resp;
  exit;
}

/* ---------------------- Upstream error: serve cache if we have it ---------------------- */
if (is_readable($CACHE_PATH)) {
  $cached = @file_get_contents($CACHE_PATH);
  if ($cached !== false) {
    // return cached feed (200) so UI keeps working
    http_response_code(200);
    echo $cached;
    exit;
  }
}

/* ---------------------- No cache and upstream failed ---------------------- */
http_response_code($http_code > 0 ? $http_code : 502);
echo json_encode([
  'ok'     => false,
  'status' => $http_code ?: 0,
  'error'  => $resp === false ? ('cURL error: ' . $err) : 'Upstream error',
]);