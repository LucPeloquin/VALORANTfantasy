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

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT source_team_id, slug, name
     FROM pro_teams
     WHERE event_id = :event_id AND source_team_id IS NOT NULL
     ORDER BY id ASC'
);
$stmt->execute([':event_id' => (int)$event['id']]);
$teams = $stmt->fetchAll();

if (!$teams) {
    fwrite(STDERR, "No teams found for current event.\n");
    exit(1);
}

$cacheDir = APP_ROOT . '/public/assets/team-logos';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0775, true);
}

$downloaded = 0;
$skipped = 0;
$failed = 0;

foreach ($teams as $team) {
    $sourceTeamId = (int)($team['source_team_id'] ?? 0);
    $slug = trim((string)($team['slug'] ?? ''));
    $name = trim((string)($team['name'] ?? ''));
    if ($sourceTeamId <= 0 || $slug === '') {
        $failed++;
        echo "skip   missing id/slug: {$name}\n";
        continue;
    }

    $existing = null;
    foreach (['png', 'jpg', 'jpeg', 'webp', 'avif', 'gif', 'svg'] as $ext) {
        $candidate = $cacheDir . '/' . $sourceTeamId . '.' . $ext;
        if (is_file($candidate) && (int)filesize($candidate) > 0) {
            $existing = $candidate;
            break;
        }
    }
    if ($existing !== null) {
        $skipped++;
        echo "exists  {$sourceTeamId} {$name}\n";
        continue;
    }

    try {
        $html = httpGet('https://www.vlr.gg/team/' . $sourceTeamId . '/' . rawurlencode($slug));
    } catch (Throwable $e) {
        $failed++;
        echo "fail    {$sourceTeamId} {$name}: " . $e->getMessage() . "\n";
        continue;
    }

    if (!preg_match('/<meta\\s+property=\"og:image\"\\s+content=\"([^\"]+)\"/i', $html, $m)) {
        $failed++;
        echo "fail    {$sourceTeamId} {$name}: no og:image\n";
        continue;
    }

    $logoUrl = normalizeHttpUrl($m[1]);
    if ($logoUrl === null) {
        $failed++;
        echo "fail    {$sourceTeamId} {$name}: invalid logo url\n";
        continue;
    }

    $ext = avatarExtensionFromUrl($logoUrl);
    $target = $cacheDir . '/' . $sourceTeamId . '.' . $ext;
    if (!downloadAvatarImage($logoUrl, $target)) {
        $failed++;
        echo "fail    {$sourceTeamId} {$name}: download failed\n";
        continue;
    }

    $downloaded++;
    echo "saved   {$sourceTeamId} {$name}\n";
}

echo "\nDone.\n";
echo "Downloaded: {$downloaded}\n";
echo "Already present: {$skipped}\n";
echo "Failed: {$failed}\n";

exit($failed > 0 ? 1 : 0);
