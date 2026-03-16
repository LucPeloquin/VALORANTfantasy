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

$players = listPlayersForEvent((int)$event['id']);
$latestSync = getLatestSyncLog((int)$event['id']);

renderLayout('Players', static function () use ($event, $players, $latestSync): void {
    ?>
    <section class="card">
        <h1>Players - <?= h($event['name']) ?></h1>
        <p>Live values and metrics are sourced from VLR scraping.</p>
        <?php if ($latestSync): ?>
            <p class="meta">
                Last sync: <?= h(utcDisplay((string)$latestSync['created_at'])) ?>
                (<?= h((string)$latestSync['status']) ?>)
            </p>
        <?php endif; ?>
    </section>

    <section class="card">
        <table>
            <thead>
                <tr>
                    <th>Player</th>
                    <th>Team</th>
                    <th>Price</th>
                    <th>Rating</th>
                    <th>ACS</th>
                    <th>KD</th>
                    <th>KAST</th>
                    <th>KPR</th>
                    <th>APR</th>
                    <th>FKPR</th>
                    <th>FDPR</th>
                    <th>CL%</th>
                    <th>Source</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($players as $p): ?>
                    <tr>
                        <td>
                            <strong><a href="/player.php?id=<?= (int)$p['id'] ?>"><?= h($p['alias']) ?></a></strong>
                            <?php if (!empty($p['real_name'])): ?>
                                <div class="meta"><?= h($p['real_name']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= h($p['short_name']) ?></td>
                        <td><?= h(formatMoney((int)$p['price'])) ?></td>
                        <td><?= h(formatPoints((float)$p['rating'])) ?></td>
                        <td><?= h(formatPoints((float)$p['acs'])) ?></td>
                        <td><?= h(formatPoints((float)$p['kd'])) ?></td>
                        <td><?= h(formatPoints((float)$p['kast'])) ?></td>
                        <td><?= h(formatPoints((float)$p['kpr'])) ?></td>
                        <td><?= h(formatPoints((float)$p['apr'])) ?></td>
                        <td><?= h(formatPoints((float)$p['fkpr'])) ?></td>
                        <td><?= h(formatPoints((float)$p['fdpr'])) ?></td>
                        <td><?= h(formatPoints((float)$p['cl_pct'])) ?></td>
                        <td><small><?= h((string)$p['source_primary']) ?><?= !empty($p['source_secondary']) ? ' + ' . h((string)$p['source_secondary']) : '' ?></small></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php
}, $user);
