<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/src/bootstrap.php';

$user = currentUser();
$slug = (string)($_GET['slug'] ?? '');
$team = $slug !== '' ? getPublicTeamBySlug($slug) : null;

if (!$team) {
    http_response_code(404);
    exit('Team not found.');
}

$isOwner = $user && (int)$user['id'] === (int)$team['user_id'];
if ((int)$team['is_public'] !== 1 && !$isOwner) {
    http_response_code(403);
    exit('This team profile is private.');
}

$score = computeFantasyTeamScore((int)$team['id']);
$lineup = $score['lineup'];
$shareUrl = appBaseUrl() . '/team.php?slug=' . urlencode((string)$team['share_slug']);
$discordPreview = appBaseUrl() . '/share.php?slug=' . urlencode((string)$team['share_slug']);
$shareImage = appBaseUrl() . '/share-image.php?slug=' . urlencode((string)$team['share_slug']);

$owner = (string)($team['display_name'] ?: $team['username']);
$metaTitle = $team['team_name'] . ' - VCT Fantasy';
$metaParts = [];
foreach ($lineup as $row) {
    $metaParts[] = $row['alias'] . ' (' . (powerCatalog()[$row['power_key']]['label'] ?? $row['power_key']) . ')';
}
$metaDescription = 'By ' . $owner . ' | Total ' . formatPoints((float)$score['total']) . ' pts | ' . implode(' | ', $metaParts);

renderLayout('Team Profile', static function () use ($team, $score, $lineup, $shareUrl, $discordPreview, $isOwner): void {
    ?>
    <section class="card">
        <h1><?= h($team['team_name']) ?></h1>
        <p>Owner: <strong><?= h((string)($team['display_name'] ?: $team['username'])) ?></strong></p>
        <p>League: <?= h($team['league_name']) ?> | Event: <?= h($team['event_name']) ?></p>
        <p>Total points: <strong><?= h(formatPoints((float)$score['total'])) ?></strong></p>
        <p>Status: <?= !empty($team['submitted_at']) ? 'Submitted' : 'Draft' ?></p>
    </section>

    <section class="card">
        <h2>Lineup</h2>
        <table>
            <thead>
                <tr><th>Player</th><th>Team</th><th>Role</th><th>Superpower</th><th>Base</th><th>Role</th><th>Power</th><th>Total</th></tr>
            </thead>
            <tbody>
            <?php foreach ($lineup as $row): ?>
                <tr>
                    <td><?= h($row['alias']) ?></td>
                    <td><?= h($row['short_name']) ?></td>
                    <td><?= h(roleCatalog()[$row['role_key']]['label'] ?? $row['role_key']) ?></td>
                    <td>
                        <?= h(powerCatalog()[$row['power_key']]['label'] ?? $row['power_key']) ?>
                        <?php if ($row['power_key'] !== 'none'): ?>
                            <small><?= $row['score']['power_triggered'] ? 'ok' : 'no' ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?= h(formatPoints((float)$row['score']['base'])) ?></td>
                    <td><?= h(formatPoints((float)$row['score']['role_bonus'])) ?></td>
                    <td><?= h(formatPoints((float)$row['score']['power_bonus'])) ?></td>
                    <td><strong><?= h(formatPoints((float)$row['score']['total'])) ?></strong></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="card">
        <h2>Share</h2>
        <p>Team profile URL: <code><?= h($shareUrl) ?></code></p>
        <p>Discord embed URL: <code><?= h($discordPreview) ?></code></p>
        <p>Share either URL in Discord; embed previews include lineup + superpowers.</p>
        <?php if ($isOwner && (int)$team['is_public'] !== 1): ?>
            <p class="danger">Your profile is private. Enable public profile in Team Builder to let others view/embed it.</p>
        <?php endif; ?>
    </section>
    <?php
}, $user, [
    'title' => $metaTitle,
    'description' => $metaDescription,
    'image' => $shareImage,
    'url' => $shareUrl,
]);
