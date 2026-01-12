<?php

// ✅ Session MUST start before helper is loaded
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$userId = $_SESSION['user']['id'] ?? 'guest';

/* ==========================
   User-specific log folder
========================== */
$logBase = __DIR__ . "/../../logger/logs/user_{$userId}";
if (!is_dir($logBase)) {
    mkdir($logBase, 0777, true);
}

// ✅ Load per-session QA iteration helper
require_once __DIR__ . '/../../logger/iteration_logic/qa_iteration_helper.php';

// Read JSON payload
$data = json_decode(file_get_contents('php://input'), true);

// Validate input
if (!$data || empty($data['timestamp'])) {
    http_response_code(400);
    exit;
}

// Assign iteration (per PHP session)
$iteration = qa_assign_iteration_id($data['timestamp']);
if ($iteration === null) {
    http_response_code(204);
    exit; // logging stopped for this session
}

$session = qa_get_session_id();

// Attach metadata
$data['iteration_id'] = $iteration;
$data['session_id']   = $session;
$data['user_id']      = $userId;
$data['php_session']  = session_id();

// Write log
$file = "{$logBase}/frontend_logs_{$session}.jsonl";
file_put_contents($file, json_encode($data) . PHP_EOL, FILE_APPEND);

http_response_code(204);
