<?php
declare(strict_types=1);

function renderLayout(string $title, callable $body, ?array $user = null, array $meta = []): void
{
    $flashes = consumeFlashes();
    $metaTitle = (string)($meta['title'] ?? $title);
    $metaDescription = (string)($meta['description'] ?? '');
    $metaImage = (string)($meta['image'] ?? '');
    $metaUrl = (string)($meta['url'] ?? '');
    $metaNoIndex = (bool)($meta['noindex'] ?? false);
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= h($title) ?></title>
        <?php if ($metaDescription !== ''): ?>
            <meta name="description" content="<?= h($metaDescription) ?>">
        <?php endif; ?>
        <?php if ($metaNoIndex): ?>
            <meta name="robots" content="noindex,nofollow">
        <?php endif; ?>
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="VCT Fantasy">
        <meta property="og:title" content="<?= h($metaTitle) ?>">
        <?php if ($metaDescription !== ''): ?>
            <meta property="og:description" content="<?= h($metaDescription) ?>">
        <?php endif; ?>
        <?php if ($metaUrl !== ''): ?>
            <meta property="og:url" content="<?= h($metaUrl) ?>">
            <link rel="canonical" href="<?= h($metaUrl) ?>">
        <?php endif; ?>
        <?php if ($metaImage !== ''): ?>
            <meta property="og:image" content="<?= h($metaImage) ?>">
            <meta name="twitter:card" content="summary_large_image">
            <meta name="twitter:image" content="<?= h($metaImage) ?>">
        <?php else: ?>
            <meta name="twitter:card" content="summary">
        <?php endif; ?>
        <meta name="twitter:title" content="<?= h($metaTitle) ?>">
        <?php if ($metaDescription !== ''): ?>
            <meta name="twitter:description" content="<?= h($metaDescription) ?>">
        <?php endif; ?>
        <link rel="stylesheet" href="/styles.css">
    </head>
    <body>
        <header class="topbar">
            <div class="wrap nav">
                <a class="brand" href="/index.php">VCT Fantasy</a>
                <nav>
                    <a href="/index.php">Home</a>
                    <a href="/players.php">Players</a>
                    <a href="/rules.php">Rules & Pricing</a>
                    <?php if ($user): ?>
                        <a href="/dashboard.php">Dashboard</a>
                        <?php if ((int)$user['is_admin'] === 1): ?>
                            <a href="/admin.php">Admin</a>
                        <?php endif; ?>
                        <form method="post" action="/logout.php" class="inline-form">
                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                            <button class="linklike" type="submit">Logout</button>
                        </form>
                    <?php else: ?>
                        <a href="/login.php">Sign in</a>
                        <a href="/register.php">Register</a>
                    <?php endif; ?>
                </nav>
            </div>
        </header>

        <main class="wrap page">
            <?php foreach ($flashes as $flash): ?>
                <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
            <?php endforeach; ?>
            <?php $body(); ?>
        </main>
    </body>
    </html>
    <?php
}
