<?php
session_start();

date_default_timezone_set('Asia/Manila');

function errorHandler($errno, $errstr, $errfile, $errline)
{
    $logMessage = "[" . date("Y-m-d H:i:s") . "] Error: [$errno] $errstr in $errfile on line $errline" . PHP_EOL;
    file_put_contents(__DIR__ . '/error_log.txt', $logMessage, FILE_APPEND);
}

set_error_handler("errorHandler");

$servername = "192.168.101.68";
$database   = "LOGGER";
$username   = "sa";
$password   = "SB1Admin";

$options = [
    PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8,
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
];

try {
    $conn = new PDO(
        "sqlsrv:Server=$servername;Database=$database;TrustServerCertificate=true",
        $username,
        $password,
        $options
    );
} catch (PDOException $e) {
    errorHandler(E_WARNING, $e->getMessage(), $e->getFile(), $e->getLine());
    die("Connection failed.");
}

function qa_db(): PDO
{
    global $conn;
    return $conn;
}
