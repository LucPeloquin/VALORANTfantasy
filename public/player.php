<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/src/bootstrap.php';

$user = currentUser();
$event = getCurrentEvent();
if (!$event) {
    http_response_code(500);
    exit('No active event.');
}

$playerId = (int)($_GET['id'] ?? 0);
if ($playerId <= 0) {
    http_response_code(404);
    exit('Player not found.');
}

$player = getPlayerProfile($playerId, (int)$event['id']);
if (!$player || (int)$player['is_active'] !== 1) {
    http_response_code(404);
    exit('Player not found.');
}

$roles = roleCatalog();
$powers = powerCatalog();

$roleProjection = [];
foreach ($roles as $roleKey => $roleMeta) {
    $score = computePlayerFantasyScore($player, $roleKey, 'none');
    $roleProjection[] = [
        'role_key' => $roleKey,
        'label' => $roleMeta['label'],
        'rule' => $roleMeta['rule'],
        'score' => $score,
    ];
}

$powerProjection = [];
foreach ($powers as $powerKey => $powerMeta) {
    if ($powerKey === 'none') {
        continue;
    }
    $score = computePlayerFantasyScore($player, 'star', $powerKey);
    $powerProjection[] = [
        'power_key' => $powerKey,
        'label' => $powerMeta['label'],
        'rule' => $powerMeta['rule'],
        'score' => $score,
    ];
}

$raw = [];
if (!empty($player['raw_json']) && is_string($player['raw_json'])) {
    $decoded = json_decode($player['raw_json'], true);
    if (is_array($decoded)) {
        $raw = $decoded;
    }
}

renderLayout('Player Stats', static function () use ($event, $player, $roleProjection, $powerProjection, $raw): void {
    ?>
    <section class="card">
        <h1><?= h($player['alias']) ?> - Player Stats</h1>
        <?php if (!empty($player['real_name'])): ?>
            <p><?= h((string)$player['real_name']) ?></p>
        <?php endif; ?>
        <p>
            Team: <strong><?= h((string)$player['team_name']) ?></strong> (<?= h((string)$player['short_name']) ?>)
            | Event: <strong><?= h($event['name']) ?></strong>
        </p>
        <p>
            Price: <strong><?= h(formatMoney((int)$player['price'])) ?></strong>
            | Pricing score: <strong><?= h(formatPoints((float)$player['pricing_score'])) ?></strong>
        </p>
        <p class="meta">
            Source: <?= h((string)$player['source_primary']) ?><?= !empty($player['source_secondary']) ? ' + ' . h((string)$player['source_secondary']) : '' ?>
            <?php if (!empty($player['stats_updated_at'])): ?>
                | Updated: <?= h(utcDisplay((string)$player['stats_updated_at'])) ?>
            <?php endif; ?>
        </p>
        <p><a href="/players.php">Back to players</a></p>
    </section>

    <section class="card">
        <h2>Performance Metrics</h2>
        <table>
            <thead>
                <tr>
                    <th>Rating</th>
                    <th>ACS</th>
                    <th>KD</th>
                    <th>KAST</th>
                    <th>ADR</th>
                    <th>KPR</th>
                    <th>APR</th>
                    <th>FKPR</th>
                    <th>FDPR</th>
                    <th>HS%</th>
                    <th>CL%</th>
                    <th>Rounds</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?= h(formatPoints((float)$player['rating'])) ?></td>
                    <td><?= h(formatPoints((float)$player['acs'])) ?></td>
                    <td><?= h(formatPoints((float)$player['kd'])) ?></td>
                    <td><?= h(formatPoints((float)$player['kast'])) ?></td>
                    <td><?= h(formatPoints((float)$player['adr'])) ?></td>
                    <td><?= h(formatPoints((float)$player['kpr'])) ?></td>
                    <td><?= h(formatPoints((float)$player['apr'])) ?></td>
                    <td><?= h(formatPoints((float)$player['fkpr'])) ?></td>
                    <td><?= h(formatPoints((float)$player['fdpr'])) ?></td>
                    <td><?= h(formatPoints((float)$player['hs_pct'])) ?></td>
                    <td><?= h(formatPoints((float)$player['cl_pct'])) ?></td>
                    <td><?= (int)$player['rounds_played'] ?></td>
                </tr>
            </tbody>
        </table>
    </section>

    <section class="grid two">
        <div class="card">
            <h2>Role Projection (No Power)</h2>
            <table>
                <thead>
                    <tr><th>Role</th><th>Rule</th><th>Total</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($roleProjection as $row): ?>
                        <tr>
                            <td><?= h((string)$row['label']) ?></td>
                            <td><?= h((string)$row['rule']) ?></td>
                            <td><strong><?= h(formatPoints((float)$row['score']['total'])) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card">
            <h2>Superpower Trigger Check (Star Role)</h2>
            <table>
                <thead>
                    <tr><th>Power</th><th>Rule</th><th>Triggered</th><th>Total</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($powerProjection as $row): ?>
                        <tr>
                            <td><?= h((string)$row['label']) ?></td>
                            <td><?= h((string)$row['rule']) ?></td>
                            <td><?= $row['score']['power_triggered'] ? 'Yes' : 'No' ?></td>
                            <td><strong><?= h(formatPoints((float)$row['score']['total'])) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if ($raw): ?>
        <section class="card">
            <h2>Raw Synced Stats</h2>
            <pre><?= h((string)json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
        </section>
    <?php endif; ?>
    <?php
}, $user);
