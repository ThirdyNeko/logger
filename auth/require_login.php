<?php
session_name('QA_LOGGER_SESSION');
session_start();
define('BASE_URL', '/logger/');

define('SESSION_TIMEOUT', 3600); // 60 minutes

// Not logged in
if (empty($_SESSION['user'])) {
    header('Location: ' . BASE_URL . 'auth/login.php');
    exit;
}

// Inactivity timeout
if (isset($_SESSION['LAST_ACTIVITY']) &&
    (time() - $_SESSION['LAST_ACTIVITY'] > SESSION_TIMEOUT)) {

    $_SESSION = [];
    session_destroy();

    header('Location: ' . BASE_URL . 'auth/login.php');
    exit;
}

// Update last activity time
$_SESSION['LAST_ACTIVITY'] = time();
