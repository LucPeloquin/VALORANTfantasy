<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$db = $root . '/data/app.sqlite';
$snapshot = $root . '/data/vct_americas_stage1_snapshot.json';

if (is_file($db)) {
    unlink($db);
    echo "Deleted DB: $db\n";
}

if (is_file($snapshot)) {
    unlink($snapshot);
    echo "Deleted snapshot: $snapshot\n";
}

echo "Done. Database and snapshot will regenerate on next app boot/sync.\n";
