<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/src/bootstrap.php';

$user = requireAuth();
$leagueId = (int)($_GET['league_id'] ?? 0);
$league = getLeague($leagueId);

if (!$league || !isUserInLeague($leagueId, (int)$user['id'])) {
    http_response_code(404);
    exit('League not found.');
}

$rows = getLeagueLeaderboard($leagueId);
$latestSync = getLatestSyncLog((int)$league['event_id']);

renderLayout('Leaderboard', static function () use ($league, $rows, $user, $latestSync): void {
    ?>
    <section class="card">
        <h1><?= h($league['name']) ?> - Leaderboard</h1>
        <p>Invite code: <code><?= h($league['join_code']) ?></code></p>
        <p>Event: <?= h($league['event_name']) ?> | Lock: <?= h(utcDisplay((string)$league['lock_at'])) ?></p>
        <?php if ($latestSync): ?>
            <p class="meta">
                Stats last synced: <?= h(utcDisplay((string)$latestSync['created_at'])) ?>
                (<?= h((string)$latestSync['status']) ?>)
            </p>
        <?php endif; ?>
        <p><a href="/team-builder.php?league_id=<?= (int)$league['id'] ?>">Open team builder</a></p>
    </section>

    <section class="card">
        <table>
            <thead>
                <tr><th>#</th><th>User</th><th>Team</th><th>Status</th><th>Points</th><th>Profile</th></tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $i => $r): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= h((string)($r['display_name'] ?: $r['username'])) ?></td>
                        <td><?= h((string)($r['team_name'] ?: 'No team')) ?></td>
                        <td><?= !empty($r['submitted_at']) ? 'Submitted' : 'Draft' ?></td>
                        <td><strong><?= h(formatPoints((float)$r['points'])) ?></strong></td>
                        <td>
                            <?php if ((int)$r['is_public'] === 1 && !empty($r['share_slug'])): ?>
                                <a href="/team.php?slug=<?= h((string)$r['share_slug']) ?>">View</a>
                            <?php else: ?>
                                <?= (int)$r['user_id'] === (int)$user['id'] ? '<em>Private (you)</em>' : '<em>Private</em>' ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php
}, $user);
