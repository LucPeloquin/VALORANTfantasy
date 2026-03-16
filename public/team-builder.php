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

$teamId = ensureFantasyTeam($leagueId, (int)$user['id']);
$team = getFantasyTeamByLeagueAndUser($leagueId, (int)$user['id']);
if (!$team) {
    http_response_code(500);
    exit('Team creation failed.');
}

$locked = rosterLocked($league);

if (isPost()) {
    verifyCsrfOrFail();

    if ($locked) {
        flash('error', 'Roster is locked.');
        redirect('/team-builder.php?league_id=' . $leagueId);
    }

    $selection = [];
    $playerIds = $_POST['player_id'] ?? [];
    $roles = $_POST['role_key'] ?? [];
    $powers = $_POST['power_key'] ?? [];
    $count = min(count($playerIds), count($roles), count($powers));
    for ($i = 0; $i < $count; $i++) {
        $selection[] = [
            'player_id' => (int)$playerIds[$i],
            'role_key' => (string)$roles[$i],
            'power_key' => (string)$powers[$i],
        ];
    }

    $teamName = (string)($_POST['team_name'] ?? 'My VCT Team');
    $isPublic = boolFromPost('is_public');
    $submitNow = boolFromPost('submit_team');

    $errors = saveFantasyRoster((int)$team['id'], $league, $teamName, $isPublic, $submitNow, $selection);
    if ($errors) {
        foreach ($errors as $e) {
            flash('error', $e);
        }
    } else {
        flash('success', $submitNow ? 'Team submitted.' : 'Draft saved.');
        redirect('/team-builder.php?league_id=' . $leagueId);
    }
}

$players = listPlayersForEvent((int)$league['event_id']);
$currentRoster = listRosterRows((int)$team['id']);
$summary = fantasyTeamSummary((int)$team['id']);
$roles = roleCatalog();
$powers = powerCatalog();

$slots = [];
foreach ($currentRoster as $r) {
    $slots[] = [
        'player_id' => (int)$r['player_id'],
        'role_key' => (string)$r['role_key'],
        'power_key' => (string)$r['power_key'],
    ];
}
while (count($slots) < 5) {
    $slots[] = ['player_id' => 0, 'role_key' => 'star', 'power_key' => 'none'];
}

renderLayout('Team Builder', static function () use ($user, $league, $team, $locked, $players, $slots, $summary, $roles, $powers): void {
    ?>
    <section class="card">
        <h1>Team Builder</h1>
        <p><strong><?= h($league['name']) ?></strong> - <?= h($league['event_name']) ?></p>
        <p>Lock: <strong><?= h(utcDisplay((string)$league['lock_at'])) ?></strong></p>
        <p>Budget: <strong><?= h(formatMoney((int)$league['budget'])) ?></strong> | Spent: <strong><?= h(formatMoney((int)$summary['spent'])) ?></strong> | Players: <strong><?= (int)$summary['players_count'] ?>/5</strong></p>
        <?php if ($locked): ?><p class="danger">Roster is locked.</p><?php endif; ?>
    </section>

    <section class="card">
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <label>Fantasy team name <input name="team_name" maxlength="60" value="<?= h((string)$team['team_name']) ?>" <?= $locked ? 'disabled' : '' ?>></label>
            <label class="inline-check"><input type="checkbox" name="is_public" value="1" <?= (int)$team['is_public'] === 1 ? 'checked' : '' ?> <?= $locked ? 'disabled' : '' ?>> Public team profile</label>

            <table>
                <thead>
                    <tr><th>Slot</th><th>Player</th><th>Role</th><th>Superpower</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($slots as $i => $slot): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td>
                                <select name="player_id[]" required <?= $locked ? 'disabled' : '' ?>>
                                    <option value="">Select player</option>
                                    <?php foreach ($players as $p): ?>
                                        <option value="<?= (int)$p['id'] ?>" <?= (int)$slot['player_id'] === (int)$p['id'] ? 'selected' : '' ?>>
                                            <?= h($p['alias']) ?> (<?= h($p['short_name']) ?>) - <?= h(formatMoney((int)$p['price'])) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <select name="role_key[]" <?= $locked ? 'disabled' : '' ?>>
                                    <?php foreach ($roles as $key => $meta): ?>
                                        <option value="<?= h($key) ?>" <?= $slot['role_key'] === $key ? 'selected' : '' ?>><?= h($meta['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <select name="power_key[]" <?= $locked ? 'disabled' : '' ?>>
                                    <?php foreach ($powers as $key => $meta): ?>
                                        <option value="<?= h($key) ?>" <?= $slot['power_key'] === $key ? 'selected' : '' ?>><?= h($meta['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if (!$locked): ?>
                <div class="button-row">
                    <button type="submit">Save draft</button>
                    <button type="submit" name="submit_team" value="1">Submit team</button>
                </div>
            <?php endif; ?>
        </form>
    </section>

    <section class="grid two">
        <div class="card">
            <h2>Role Bonuses</h2>
            <ul>
                <?php foreach ($roles as $role): ?>
                    <li><strong><?= h($role['label']) ?>:</strong> <?= h($role['rule']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="card">
            <h2>Superpowers</h2>
            <ul>
                <?php foreach ($powers as $power): ?>
                    <li><strong><?= h($power['label']) ?>:</strong> <?= h($power['rule']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </section>

    <section class="card">
        <p><a href="/leaderboard.php?league_id=<?= (int)$league['id'] ?>">Open leaderboard</a></p>
    </section>
    <?php
}, $user);
