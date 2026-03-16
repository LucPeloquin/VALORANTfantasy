<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/src/bootstrap.php';

if (!isPost()) {
    redirect('/index.php');
}

verifyCsrfOrFail();
logoutUser();
flash('success', 'You have been logged out.');
redirect('/index.php');
