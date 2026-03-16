<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/src/bootstrap.php';

if (currentUser()) {
    redirect('/dashboard.php');
}

if (isPost()) {
    verifyCsrfOrFail();

    $username = (string)($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if (loginUser($username, $password)) {
        flash('success', 'Signed in successfully.');
        redirect('/dashboard.php');
    }

    flash('error', 'Invalid username or password.');
}

renderLayout('Sign in', static function (): void {
    ?>
    <section class="card narrow-card">
        <h1>Sign in</h1>
        <p>Use your account to build and submit your fantasy team.</p>
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <label>Username <input name="username" required maxlength="20" autocomplete="username"></label>
            <label>Password <input type="password" name="password" required autocomplete="current-password"></label>
            <button type="submit">Sign in</button>
        </form>
        <p>No account yet? <a href="/register.php">Create one</a>.</p>
    </section>
    <?php
});
