<?php
// report_ping.php — lightweight heartbeat that only logs to data/cron.jsonl

header('Content-Type: application/json; charset=utf-8');

$ROOT    = __DIR__;
$DATA    = $ROOT . '/data';
$CRONLOG = $DATA . '/cron.jsonl';
$TZNAME  = 'Europe/London';

try { $TZ = new DateTimeZone($TZNAME); } catch (Exception $e) { $TZ = new DateTimeZone('UTC'); }

function ensure_data_dir($dir) { if (!is_dir($dir)) @mkdir($dir, 0775, true); }

function cron_log($job, $status, $fields = []) {
  global $CRONLOG, $TZ;
  ensure_data_dir(dirname($CRONLOG));
  $row = array_merge([
    'ts'     => (new DateTime('now', $TZ))->format('Y-m-d H:i:s'),
    'job'    => $job,
    'status' => $status,
    'ip'     => $_SERVER['REMOTE_ADDR'] ?? '',
    'ua'     => $_SERVER['HTTP_USER_AGENT'] ?? '',
  ], $fields);
  @file_put_contents($CRONLOG, json_encode($row, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

cron_log('archive_write', 'ok', ['heartbeat' => 1]);

echo json_encode(['ok'=>true, 'heartbeat'=>1], JSON_UNESCAPED_SLASHES);