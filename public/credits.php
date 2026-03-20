<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/src/bootstrap.php';

$user = currentUser();

renderLayout('Credits', static function (): void {
    ?>
    <section class="card">
        <h1>Credits</h1>
        <p>This fantasy project is built in PHP + SQLite with a custom HLTV-inspired UI.</p>
        <ul>
            <li><strong>Data source:</strong> VLR.gg (scraped and normalized).</li>
            <li><strong>Core app:</strong> Internal project codebase.</li>
            <li><strong>Player imagery:</strong> Cached local assets under <code>public/assets/player-images/</code>.</li>
        </ul>
    </section>
    <?php
}, $user);
