<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/src/bootstrap.php';

$user = requireAdmin();
$event = getCurrentEvent();
if (!$event) {
    http_response_code(500);
    exit('No active event.');
}

if (isPost()) {
    verifyCsrfOrFail();

    try {
        $summary = syncEventDataFromSources((int)$event['id']);
        flash('success', 'Sync complete. Teams: ' . $summary['teams'] . ', Players: ' . $summary['players']);
    } catch (Throwable $e) {
        flash('error', 'Sync failed: ' . $e->getMessage());
    }

    redirect('/admin.php');
}

$logs = getRecentSyncLogs((int)$event['id']);
$latest = getLatestSyncLog((int)$event['id']);
$players = listPlayersForEvent((int)$event['id']);
$intervalMinutes = max(1, (int)env('AUTO_SYNC_INTERVAL_MINUTES', '30'));

renderLayout('Admin', static function () use ($event, $logs, $players, $latest, $intervalMinutes): void {
    ?>
    <section class="card">
        <h1>Admin - Data Sync</h1>
        <p>Event: <?= h($event['name']) ?> (source event ID: <?= (int)$event['source_event_id'] ?>)</p>
        <p>Auto-sync interval: <strong><?= (int)$intervalMinutes ?> minutes</strong></p>
        <?php if ($latest): ?>
            <p class="meta">
                Latest sync: <?= h(utcDisplay((string)$latest['created_at'])) ?>
                (<?= h((string)$latest['status']) ?>)
            </p>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <button type="submit">Sync from VLR</button>
        </form>
    </section>

    <section class="card">
        <h2>Current ingestion status</h2>
        <p>Active players: <strong><?= count($players) ?></strong></p>
        <?php if (!$logs): ?>
            <p>No sync logs yet.</p>
        <?php else: ?>
            <table>
                <thead><tr><th>Time (UTC)</th><th>Status</th><th>Source</th><th>Message</th></tr></thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?= h(utcDisplay((string)$log['created_at'])) ?></td>
                            <td><?= h($log['status']) ?></td>
                            <td><?= h($log['source']) ?></td>
                            <td><?= h((string)$log['message']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
    <?php
}, $user);
