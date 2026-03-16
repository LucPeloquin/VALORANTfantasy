<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dataDir = APP_ROOT . '/data';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0775, true);
    }

    $dbPath = $dataDir . '/app.sqlite';
    $firstBoot = !is_file($dbPath);

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');

    if ($firstBoot) {
        $schema = file_get_contents(APP_ROOT . '/data/schema.sql');
        if ($schema === false) {
            throw new RuntimeException('Could not read schema.sql');
        }
        $pdo->exec($schema);
        seedCoreRows($pdo);
    }

    return $pdo;
}

function seedCoreRows(PDO $pdo): void
{
    $now = nowUtc();

    $adminStmt = $pdo->prepare(
        'INSERT INTO users (username, display_name, password_hash, is_admin, created_at, last_login_at)
         VALUES (:username, :display_name, :password_hash, 1, :created_at, :last_login_at)'
    );
    $adminStmt->execute([
        ':username' => 'admin',
        ':display_name' => 'Admin',
        ':password_hash' => password_hash('test', PASSWORD_DEFAULT),
        ':created_at' => $now,
        ':last_login_at' => $now,
    ]);

    $eventStmt = $pdo->prepare(
        'INSERT INTO events (name, slug, source, source_event_id, season, lock_at, status, budget, max_from_team, created_at)
         VALUES (:name, :slug, :source, :source_event_id, :season, :lock_at, :status, :budget, :max_from_team, :created_at)'
    );

    $eventStmt->execute([
        ':name' => 'VCT 2026: Americas Stage 1',
        ':slug' => 'vct-2026-americas-stage-1',
        ':source' => 'vlr',
        ':source_event_id' => 2860,
        ':season' => '2026',
        ':lock_at' => gmdate('c', strtotime('+7 days 18:00:00 UTC')),
        ':status' => 'open',
        ':budget' => 1000000,
        ':max_from_team' => 2,
        ':created_at' => $now,
    ]);
}
