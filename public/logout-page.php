<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/src/bootstrap.php';

$user = requireAuth();

renderLayout('Logout', static function (): void {
    ?>
    <section class="card narrow-card">
        <h1>Logout</h1>
        <p>Confirm to end your session.</p>
        <form method="post" action="/logout.php">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <div class="button-row">
                <button type="submit">Logout</button>
                <a class="button ghost" href="/dashboard.php">Cancel</a>
            </div>
        </form>
    </section>
    <?php
}, $user);
