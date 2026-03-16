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
    $displayName = (string)($_POST['display_name'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $passwordConfirm = (string)($_POST['password_confirm'] ?? '');

    if ($password !== $passwordConfirm) {
        flash('error', 'Passwords do not match.');
        redirect('/register.php');
    }

    try {
        registerUser($username, $displayName, $password);
        flash('success', 'Account created.');
        redirect('/dashboard.php');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('/register.php');
    }
}

renderLayout('Register', static function (): void {
    ?>
    <section class="card narrow-card">
        <h1>Create account</h1>
        <p>Username rules: 3-20 chars, lowercase letters/numbers/underscore.</p>
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <label>Username <input name="username" required maxlength="20" autocomplete="username" pattern="[a-z0-9_]{3,20}"></label>
            <label>Display name <input name="display_name" maxlength="30" autocomplete="nickname"></label>
            <label>Password <input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
            <label>Confirm password <input type="password" name="password_confirm" required minlength="8" autocomplete="new-password"></label>
            <button type="submit">Create account</button>
        </form>
        <p>Already have an account? <a href="/login.php">Sign in</a>.</p>
    </section>
    <?php
});
