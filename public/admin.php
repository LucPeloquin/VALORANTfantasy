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

$panelUsername = (string)env('ADMIN_PANEL_USERNAME', 'admin');
$panelPassword = (string)env('ADMIN_PANEL_PASSWORD', 'brenlets');
$panelSessionKey = 'admin_panel_unlocked';
$panelUnlocked = (bool)($_SESSION[$panelSessionKey] ?? false);

if (isPost() && (string)($_POST['admin_gate_action'] ?? '') === 'unlock_panel') {
    verifyCsrfOrFail();

    $inputUser = trim((string)($_POST['panel_username'] ?? ''));
    $inputPass = (string)($_POST['panel_password'] ?? '');
    if (hash_equals($panelUsername, $inputUser) && hash_equals($panelPassword, $inputPass)) {
        $_SESSION[$panelSessionKey] = true;
        flash('success', 'Admin panel unlocked.');
    } else {
        flash('error', 'Invalid admin panel credentials.');
    }

    redirect('/admin.php');
}

if (isPost() && (string)($_POST['admin_gate_action'] ?? '') === 'lock_panel') {
    verifyCsrfOrFail();
    unset($_SESSION[$panelSessionKey]);
    flash('success', 'Admin panel locked.');
    redirect('/admin.php');
}

if (!$panelUnlocked) {
    renderLayout('Admin Access', static function (): void {
        ?>
        <section class="card admin-lock-card">
            <h1>Admin Panel Locked</h1>
            <p>Enter admin panel credentials to continue.</p>
            <form method="post" class="admin-lock-form">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="admin_gate_action" value="unlock_panel">
                <label>
                    Username
                    <input type="text" name="panel_username" autocomplete="username" required>
                </label>
                <label>
                    Password
                    <input type="password" name="panel_password" autocomplete="current-password" required>
                </label>
                <button type="submit">Unlock panel</button>
            </form>
        </section>
        <?php
    }, $user);
    exit;
}

if (isPost()) {
    verifyCsrfOrFail();

    $parsePrice = static function (mixed $value): int {
        $raw = trim((string)$value);
        $clean = str_replace(['$', ',', ' '], '', $raw);
        if ($clean === '' || !preg_match('/^-?\d+$/', $clean)) {
            throw new InvalidArgumentException('Price must be an integer.');
        }

        $price = (int)$clean;
        if ($price < 0) {
            throw new InvalidArgumentException('Price cannot be negative.');
        }

        return $price;
    };

    $parseOptionalFloat = static function (mixed $value): ?float {
        $raw = trim((string)$value);
        if ($raw === '') {
            return null;
        }

        $clean = str_replace(['%', ',', ' '], '', $raw);
        if (!is_numeric($clean)) {
            throw new InvalidArgumentException('Stat values must be numeric.');
        }

        return (float)$clean;
    };

    $action = (string)($_POST['action'] ?? 'sync');

    try {
        if ($action === 'sync') {
            $summary = syncEventDataFromSources((int)$event['id']);
            flash('success', 'Sync complete. Teams: ' . $summary['teams'] . ', Players: ' . $summary['players']);
        } elseif ($action === 'update_player') {
            $playerId = (int)($_POST['player_id'] ?? 0);
            if ($playerId <= 0) {
                throw new InvalidArgumentException('Invalid player ID.');
            }

            $payload = [
                'alias' => (string)($_POST['alias'] ?? ''),
                'real_name' => (string)($_POST['real_name'] ?? ''),
                'avatar_url' => (string)($_POST['avatar_url'] ?? ''),
                'price' => $parsePrice($_POST['price'] ?? ''),
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
                'rating' => $parseOptionalFloat($_POST['rating'] ?? null),
                'acs' => $parseOptionalFloat($_POST['acs'] ?? null),
                'kd' => $parseOptionalFloat($_POST['kd'] ?? null),
                'kast' => $parseOptionalFloat($_POST['kast'] ?? null),
                'kpr' => $parseOptionalFloat($_POST['kpr'] ?? null),
                'apr' => $parseOptionalFloat($_POST['apr'] ?? null),
                'fkpr' => $parseOptionalFloat($_POST['fkpr'] ?? null),
                'fdpr' => $parseOptionalFloat($_POST['fdpr'] ?? null),
                'cl_pct' => $parseOptionalFloat($_POST['cl_pct'] ?? null),
            ];

            adminUpdatePlayerValues((int)$event['id'], $playerId, $payload);
            flash('success', 'Updated player #' . $playerId . '.');
        } else {
            throw new InvalidArgumentException('Unknown admin action.');
        }
    } catch (Throwable $e) {
        if ($action === 'sync') {
            flash('error', 'Sync failed: ' . $e->getMessage());
        } else {
            flash('error', 'Update failed: ' . $e->getMessage());
        }
    }

    redirect('/admin.php');
}

$logs = getRecentSyncLogs((int)$event['id']);
$latest = getLatestSyncLog((int)$event['id']);
$players = listPlayersForAdmin((int)$event['id']);
$intervalMinutes = max(1, (int)env('AUTO_SYNC_INTERVAL_MINUTES', '30'));

renderLayout('Admin', static function () use ($event, $logs, $players, $latest, $intervalMinutes): void {
    $activePlayers = array_values(array_filter($players, static fn(array $p): bool => (int)$p['is_active'] === 1));
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
            <input type="hidden" name="action" value="sync">
            <button type="submit">Sync from VLR</button>
        </form>
        <form method="post" class="admin-lock-inline-form">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="admin_gate_action" value="lock_panel">
            <button type="submit" class="button ghost">Lock panel</button>
        </form>
    </section>

    <section class="card admin-player-editor">
        <h2>Player values editor</h2>
        <p class="meta">Edit alias, price, avatar, and core stats directly in UI. Save each row individually.</p>
        <p class="meta">Note: sync is stats-only. Team/player/price fields are not overwritten by source sync.</p>

        <div class="table-wrap">
            <table class="admin-player-table">
                <thead>
                    <tr>
                        <th>Player</th>
                        <th>Team</th>
                        <th>Active</th>
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
                        <th>Avatar URL</th>
                        <th>Save</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($players as $p): ?>
                        <?php $formId = 'player-edit-' . (int)$p['id']; ?>
                        <tr class="<?= (int)$p['is_active'] === 1 ? '' : 'is-inactive' ?>">
                            <td>
                                <form id="<?= h($formId) ?>" method="post" class="inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="update_player">
                                    <input type="hidden" name="player_id" value="<?= (int)$p['id'] ?>">
                                </form>
                                <input form="<?= h($formId) ?>" name="alias" value="<?= h((string)$p['alias']) ?>" required class="admin-input-alias">
                                <input form="<?= h($formId) ?>" name="real_name" value="<?= h((string)$p['real_name']) ?>" placeholder="Real name" class="admin-input-real">
                            </td>
                            <td class="admin-team-cell"><span class="team-tag"><?= h((string)$p['short_name']) ?></span></td>
                            <td>
                                <label class="admin-check">
                                    <input form="<?= h($formId) ?>" type="checkbox" name="is_active" value="1" <?= (int)$p['is_active'] === 1 ? 'checked' : '' ?>>
                                    <span><?= (int)$p['is_active'] === 1 ? 'Yes' : 'No' ?></span>
                                </label>
                            </td>
                            <td><input form="<?= h($formId) ?>" type="text" name="price" value="<?= h((string)(int)$p['price']) ?>" class="admin-input-stat"></td>
                            <td><input form="<?= h($formId) ?>" type="text" name="rating" value="<?= h((string)$p['rating']) ?>" class="admin-input-stat"></td>
                            <td><input form="<?= h($formId) ?>" type="text" name="acs" value="<?= h((string)$p['acs']) ?>" class="admin-input-stat"></td>
                            <td><input form="<?= h($formId) ?>" type="text" name="kd" value="<?= h((string)$p['kd']) ?>" class="admin-input-stat"></td>
                            <td><input form="<?= h($formId) ?>" type="text" name="kast" value="<?= h((string)$p['kast']) ?>" class="admin-input-stat"></td>
                            <td><input form="<?= h($formId) ?>" type="text" name="kpr" value="<?= h((string)$p['kpr']) ?>" class="admin-input-stat"></td>
                            <td><input form="<?= h($formId) ?>" type="text" name="apr" value="<?= h((string)$p['apr']) ?>" class="admin-input-stat"></td>
                            <td><input form="<?= h($formId) ?>" type="text" name="fkpr" value="<?= h((string)$p['fkpr']) ?>" class="admin-input-stat"></td>
                            <td><input form="<?= h($formId) ?>" type="text" name="fdpr" value="<?= h((string)$p['fdpr']) ?>" class="admin-input-stat"></td>
                            <td><input form="<?= h($formId) ?>" type="text" name="cl_pct" value="<?= h((string)$p['cl_pct']) ?>" class="admin-input-stat"></td>
                            <td><input form="<?= h($formId) ?>" type="text" name="avatar_url" value="<?= h((string)$p['avatar_url']) ?>" class="admin-input-avatar"></td>
                            <td><button form="<?= h($formId) ?>" type="submit" class="button">Save</button></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card">
        <h2>Current ingestion status</h2>
        <p>Active players: <strong><?= count($activePlayers) ?></strong></p>
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
