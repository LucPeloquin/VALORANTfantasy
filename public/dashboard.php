<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/src/bootstrap.php';

$user = requireAuth();
$event = getCurrentEvent();
if (!$event) {
    http_response_code(500);
    exit('No active event');
}

if (isPost()) {
    verifyCsrfOrFail();
    flash('error', 'Creating and joining leagues is temporarily disabled in single-league mode.');
    redirect('/dashboard.php');
}

$league = ensureUserInPrimaryLeague((int)$user['id'], (int)$event['id']);
$team = getFantasyTeamByLeagueAndUser((int)$league['id'], (int)$user['id']);
$points = 0.0;
if ($team) {
    $points = computeFantasyTeamScore((int)$team['id'])['total'];
}

renderLayout('Dashboard', static function () use ($user, $event, $league, $points): void {
    ?>
    <section class="card">
        <h1>Dashboard</h1>
        <p>Signed in as <strong><?= h((string)($user['display_name'] ?: $user['username'])) ?></strong>.</p>
        <p>Event: <strong><?= h($event['name']) ?></strong> | Lock: <strong><?= h(utcDisplay((string)$event['lock_at'])) ?></strong></p>
        <p class="meta">Single-league mode is enabled right now. League creation/joining is shelved.</p>
    </section>

    <section class="card">
        <h2>Main League</h2>
        <table>
            <thead>
                <tr><th>League</th><th>Event</th><th>Your Score</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td><?= h($league['name']) ?> <small><code><?= h($league['join_code']) ?></code></small></td>
                    <td><?= h($league['event_name']) ?></td>
                    <td><?= h(formatPoints((float)$points)) ?></td>
                    <td>
                        <a href="/team-builder.php?league_id=<?= (int)$league['id'] ?>">Team Builder</a>
                        |
                        <a href="/leaderboard.php?league_id=<?= (int)$league['id'] ?>">Leaderboard</a>
                    </td>
                </tr>
            </tbody>
        </table>
    </section>
    <?php
}, $user);
