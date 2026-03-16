<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

date_default_timezone_set('UTC');

require_once APP_ROOT . '/src/config.php';
loadEnvFile(APP_ROOT . '/.env');

require_once APP_ROOT . '/src/helpers.php';
require_once APP_ROOT . '/src/db.php';
require_once APP_ROOT . '/src/scoring.php';
require_once APP_ROOT . '/src/scraper.php';
require_once APP_ROOT . '/src/auth.php';
require_once APP_ROOT . '/src/repository.php';
require_once APP_ROOT . '/src/view.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Initialize DB and lazily ensure event data exists.
db();
if (PHP_SAPI !== 'cli') {
    ensureEventSynced();
}
