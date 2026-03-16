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

    if (isset($_POST['action']) && $_POST['action'] === 'create_league') {
        $name = (string)($_POST['name'] ?? '');
        $description = (string)($_POST['description'] ?? '');
        $isPublic = boolFromPost('is_public');

        try {
            $leagueId = createLeague((int)$event['id'], (int)$user['id'], $name, $description, $isPublic);
            flash('success', 'League created.');
            redirect('/leaderboard.php?league_id=' . $leagueId);
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/dashboard.php');
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'join_league') {
        $code = (string)($_POST['join_code'] ?? '');
        $league = joinLeagueByCode($code, (int)$user['id']);
        if (!$league) {
            flash('error', 'Invalid join code.');
            redirect('/dashboard.php');
        }

        flash('success', 'Joined league.');
        redirect('/leaderboard.php?league_id=' . (int)$league['id']);
    }
}

$myLeagues = listUserLeagues((int)$user['id']);

renderLayout('Dashboard', static function () use ($user, $event, $myLeagues): void {
    ?>
    <section class="card">
        <h1>Dashboard</h1>
        <p>Signed in as <strong><?= h((string)($user['display_name'] ?: $user['username'])) ?></strong>.</p>
        <p>Event: <strong><?= h($event['name']) ?></strong> | Lock: <strong><?= h(utcDisplay((string)$event['lock_at'])) ?></strong></p>
    </section>

    <section class="grid two">
        <div class="card">
            <h2>Create League</h2>
            <form method="post" class="form-grid">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="create_league">
                <label>League name <input name="name" required maxlength="80"></label>
                <label>Description <textarea name="description" rows="2" maxlength="220"></textarea></label>
                <label class="inline-check"><input type="checkbox" name="is_public" value="1"> List publicly on homepage</label>
                <button type="submit">Create</button>
            </form>
        </div>

        <div class="card">
            <h2>Join League</h2>
            <form method="post" class="form-grid">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="join_league">
                <label>Join code <input name="join_code" required style="text-transform: uppercase;" maxlength="12"></label>
                <button type="submit">Join</button>
            </form>
        </div>
    </section>

    <section class="card">
        <h2>Your Leagues</h2>
        <?php if (!$myLeagues): ?>
            <p>You have not joined any leagues yet.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr><th>League</th><th>Event</th><th>Your Score</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($myLeagues as $league):
                        $team = getFantasyTeamByLeagueAndUser((int)$league['id'], (int)$user['id']);
                        $points = 0.0;
                        if ($team) {
                            $points = computeFantasyTeamScore((int)$team['id'])['total'];
                        }
                    ?>
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
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
    <?php
}, $user);
