<?php
declare(strict_types=1);

$root = dirname(__DIR__);
define('APP_ROOT', $root);
require_once APP_ROOT . '/src/bootstrap.php';

$event = getCurrentEvent();
if (!$event) {
    fwrite(STDERR, "No active event found.\n");
    exit(1);
}

$force = in_array('--force', $argv, true);
$result = runScheduledSyncIfDue((int)$event['id'], $force);

if (!($result['ran'] ?? false)) {
    $reason = (string)($result['reason'] ?? 'skipped');
    echo "Skipped ($reason)\n";
    if (!empty($result['error'])) {
        echo "Error: " . $result['error'] . "\n";
        exit(1);
    }
    exit(0);
}

echo "Sync OK\n";
echo "Event: {$event['name']}\n";
echo 'Teams: ' . (int)($result['teams'] ?? 0) . "\n";
echo 'Players: ' . (int)($result['players'] ?? 0) . "\n";
