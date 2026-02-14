<?php
session_name('QA_LOGGER_SESSION');
session_start();

define('SESSION_TIMEOUT', 3600); // 60 minutes

// Not logged in
if (empty($_SESSION['user'])) {
    header('Location: auth/login.php');
    exit;
}

// Inactivity timeout
if (isset($_SESSION['LAST_ACTIVITY']) &&
    (time() - $_SESSION['LAST_ACTIVITY'] > SESSION_TIMEOUT)) {

    $_SESSION = [];
    session_destroy();

    header('Location: auth/login.php?timeout=1');
    exit;
}

// Update last activity time
$_SESSION['LAST_ACTIVITY'] = time();
