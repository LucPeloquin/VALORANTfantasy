<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/src/bootstrap.php';

$user = currentUser();
$event = getCurrentEvent();
$publicLeagues = $event ? listPublicLeaguesForEvent((int)$event['id']) : [];

renderLayout('VCT Fantasy - Home', static function () use ($user, $event, $publicLeagues): void {
    ?>
    <section class="hero card">
        <h1>VCT Americas Fantasy League</h1>
        <p>Build a 5-player roster for <strong>VCT Americas Stage 1</strong>, assign roles and superpowers, and compete on a live leaderboard.</p>
        <div class="button-row">
            <?php if ($user): ?>
                <a class="button" href="/dashboard.php">Open Dashboard</a>
            <?php else: ?>
                <a class="button" href="/register.php">Create Account</a>
                <a class="button ghost" href="/login.php">Sign in</a>
            <?php endif; ?>
            <a class="button ghost" href="/players.php">Browse Players</a>
            <a class="button ghost" href="/rules.php">Scoring & Pricing</a>
        </div>
    </section>

    <?php if ($event): ?>
        <section class="card">
            <h2><?= h($event['name']) ?></h2>
            <p>Lock: <strong><?= h(utcDisplay((string)$event['lock_at'])) ?></strong></p>
            <p>Budget: <strong><?= h(formatMoney((int)$event['budget'])) ?></strong> | Max from one team: <strong><?= (int)$event['max_from_team'] ?></strong></p>
        </section>
    <?php endif; ?>

    <section class="card">
        <h2>Public Leagues</h2>
        <?php if (!$publicLeagues): ?>
            <p>No public leagues yet.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr><th>League</th><th>Description</th><th>Join Code</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($publicLeagues as $league): ?>
                        <tr>
                            <td><?= h($league['name']) ?></td>
                            <td><?= h((string)$league['description']) ?></td>
                            <td><code><?= h($league['join_code']) ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
    <?php
}, $user);
