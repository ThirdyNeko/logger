<?php
// 🚫 NEVER LOG PHP DEPRECATIONS
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

// Load helpers
require_once __DIR__ . '/../iteration_logic/qa_iteration_helper.php';

// Read the POSTed log data
$data = json_decode(file_get_contents('php://input'), true);
$GLOBALS['__QA_USER_ID__'] = $data['user_id'] ?? 'guest';

// Get the user ID safely from global
$userId = $data['user_id'] ?? $GLOBALS['__QA_USER_ID__'] ?? 'guest';


/* ==========================
   User-specific log folder
========================== */
$logBase = __DIR__ . "/../logs/user_{$userId}";
if (!is_dir($logBase)) {
    mkdir($logBase, 0777, true);
}

// Validate input
if (!$data || empty($data['timestamp'])) {
    http_response_code(400);
    exit;
}

// Assign iteration & session
$iteration = qa_assign_iteration_id($data['timestamp']);
if ($iteration === null) {
    http_response_code(204);
    exit; // logging stopped
}

$session = qa_get_session_id();

// Add meta
$data['iteration_id'] = $iteration;
$data['session_id']   = $session;
$data['user_id']      = $userId;

// Write log
$file = "{$logBase}/backend_logs_{$session}.jsonl";
file_put_contents($file, json_encode($data) . PHP_EOL, FILE_APPEND);

http_response_code(204);
