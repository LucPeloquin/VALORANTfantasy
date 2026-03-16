<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/src/bootstrap.php';

$slug = (string)($_GET['slug'] ?? '');
$team = $slug !== '' ? getPublicTeamBySlug($slug) : null;
if (!$team || (int)$team['is_public'] !== 1) {
    http_response_code(404);
    exit('Team not found');
}

$score = computeFantasyTeamScore((int)$team['id']);
$lineup = $score['lineup'];

$owner = (string)($team['display_name'] ?: $team['username']);
$title = $team['team_name'] . ' - VCT Fantasy';
$parts = [];
foreach ($lineup as $row) {
    $parts[] = $row['alias'] . ' [' . (powerCatalog()[$row['power_key']]['label'] ?? $row['power_key']) . ']';
}
$description = 'By ' . $owner . ' | Total: ' . formatPoints((float)$score['total']) . ' | ' . implode(' | ', $parts);
$canonical = appBaseUrl() . '/team.php?slug=' . urlencode((string)$team['share_slug']);
$image = appBaseUrl() . '/share-image.php?slug=' . urlencode((string)$team['share_slug']);

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?></title>
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= h($title) ?>">
    <meta property="og:description" content="<?= h($description) ?>">
    <meta property="og:url" content="<?= h($canonical) ?>">
    <meta property="og:site_name" content="VCT Fantasy">
    <meta property="og:image" content="<?= h($image) ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= h($title) ?>">
    <meta name="twitter:description" content="<?= h($description) ?>">
    <meta name="twitter:image" content="<?= h($image) ?>">
    <meta http-equiv="refresh" content="0;url=<?= h($canonical) ?>">
    <link rel="canonical" href="<?= h($canonical) ?>">
</head>
<body>
    <p>Redirecting to <a href="<?= h($canonical) ?>">team profile</a>...</p>
</body>
</html>
