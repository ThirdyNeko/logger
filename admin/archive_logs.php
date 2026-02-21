<?php
require '../config/db.php';

header('Content-Type: application/json');

session_name('QA_LOGGER_SESSION');
session_start();

if (
    !isset($_SESSION['user']) ||
    !isset($_SESSION['user']['role']) ||
    $_SESSION['user']['role'] !== 'admin'
) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$db = qa_db();

$date = date('Y');
$archiveTable = "qa_logs_" . $date;

try {

    // 1️⃣ Rename current logs table
    $db->exec("EXEC sp_rename 'qa_logs', '$archiveTable'");

    // 2️⃣ Apply compression
    $db->exec("
        ALTER TABLE $archiveTable 
        REBUILD PARTITION = ALL 
        WITH (DATA_COMPRESSION = PAGE)
    ");

    // 3️⃣ Create new empty logs table
    $db->exec("SELECT TOP 0 * INTO qa_logs FROM $archiveTable");

    // 4️⃣ Truncate remarks table
    $db->exec("TRUNCATE TABLE qa_remarks");

    // 5️⃣ Truncate sessions table
    $db->exec("TRUNCATE TABLE qa_session_state");

    echo json_encode([
        'success' => true,
        'message' => "Logs archived to $archiveTable. Remarks and sessions truncated."
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}