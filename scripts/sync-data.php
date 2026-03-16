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

try {
    $summary = syncEventDataFromSources((int)$event['id']);
    echo "Sync OK\n";
    echo "Event: {$event['name']}\n";
    echo "Teams: {$summary['teams']}\n";
    echo "Players: {$summary['players']}\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Sync failed: " . $e->getMessage() . "\n");
    exit(1);
}
