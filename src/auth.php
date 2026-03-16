<?php
declare(strict_types=1);

function currentUser(): ?array
{
    $id = $_SESSION['user_id'] ?? null;
    if (!is_int($id)) {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function requireAuth(): array
{
    $user = currentUser();
    if (!$user) {
        flash('error', 'Please sign in to continue.');
        redirect('/login.php');
    }
    return $user;
}

function requireAdmin(): array
{
    $user = requireAuth();
    if ((int)$user['is_admin'] !== 1) {
        http_response_code(403);
        exit('Forbidden');
    }
    return $user;
}

function logoutUser(): void
{
    unset($_SESSION['user_id']);
}

function registerUser(string $username, string $displayName, string $password): array
{
    $username = strtolower(trim($username));
    $displayName = trim($displayName);

    if (!preg_match('/^[a-z0-9_]{3,20}$/', $username)) {
        throw new InvalidArgumentException('Username must be 3-20 chars, lowercase letters/numbers/underscore only.');
    }
    if (strlen($password) < 8) {
        throw new InvalidArgumentException('Password must be at least 8 characters.');
    }

    if ($displayName === '') {
        $displayName = $username;
    }

    $stmt = db()->prepare(
        'INSERT INTO users (username, display_name, password_hash, is_admin, created_at, last_login_at)
         VALUES (:username, :display_name, :password_hash, 0, :created_at, :last_login_at)'
    );

    try {
        $stmt->execute([
            ':username' => $username,
            ':display_name' => $displayName,
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ':created_at' => nowUtc(),
            ':last_login_at' => nowUtc(),
        ]);
    } catch (PDOException $e) {
        throw new InvalidArgumentException('Username is already taken.');
    }

    $_SESSION['user_id'] = (int)db()->lastInsertId();
    $user = currentUser();
    if (!$user) {
        throw new RuntimeException('Failed to create session.');
    }
    return $user;
}

function loginUser(string $username, string $password): bool
{
    $username = strtolower(trim($username));

    $stmt = db()->prepare('SELECT id, password_hash FROM users WHERE username = :username');
    $stmt->execute([':username' => $username]);
    $row = $stmt->fetch();

    if (!$row || !is_string($row['password_hash']) || !password_verify($password, $row['password_hash'])) {
        return false;
    }

    $_SESSION['user_id'] = (int)$row['id'];
    db()->prepare('UPDATE users SET last_login_at = :ts WHERE id = :id')
        ->execute([':ts' => nowUtc(), ':id' => (int)$row['id']]);

    return true;
}
