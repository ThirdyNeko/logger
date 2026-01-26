<?php
/* ==========================
   CORS (MUST BE FIRST)
========================== */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-QA-INTERNAL');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Max-Age: 86400');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// 🚫 Never log PHP deprecations
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../iteration_logic/qa_iteration_helper.php';

session_start();

/* ==========================
   Read frontend payload
========================== */
$data = json_decode(file_get_contents('php://input'), true);
if (!$data || empty($data['timestamp'])) {
    http_response_code(400);
    exit;
}

try {
    $userId = qa_get_user_id();
} catch (Exception $e) {
    http_response_code(403);
    exit;
}
$GLOBALS['__QA_USER_ID__']    = $data['device_name'];
$GLOBALS['__QA_PROGRAM__']   = $data['program_name'];

/* ==========================
   Assign iteration & session
========================== */

// Assign iteration (returns int)
$iteration = qa_assign_iteration_id($data['timestamp']);
if ($iteration === null) {
    http_response_code(204);
    exit;
}

// Fetch session state to get session_id
$state = qa_get_session_state();
$session_id = $state['session_id'];


/* ==========================
   Normalize UI logs
========================== */
if (($data['type'] ?? '') === 'frontend-ui' || isset($data['ui_type'])) {
    $data['type'] = 'frontend-io';
    $data['response'] = $data['message'] ?? '[UI message]';
}

/* ==========================
   Extract log data
========================== */
$type         = $data['type'] ?? 'frontend-io';
$program_name = $data['program_name'] ?? 'UNKNOWN_APP';
$device_name  = $data['device_name'] ?? 'guest';
$endpoint     = $data['url'] ?? null;
$method       = $data['method'] ?? null;
$requestBody  = isset($data['request']) ? json_encode($data['request']) : null;
$responseBody = isset($data['response']) ? json_encode($data['response']) : null;
$statusCode   = $data['status'] ?? 200;
$user_id    = $device_name;

/* ==========================
   Insert frontend log
========================== */
$db = qa_db();

$stmt = $db->prepare("
    INSERT INTO qa_logs
    (user_id, session_id, iteration, device_name, program_name, type, endpoint, method, request_body, response_body, status_code, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
");

$stmt->bind_param(
    'ssisssssssi',
    $user_id,
    $session_id,
    $iteration,
    $device_name,
    $program_name,
    $type,
    $endpoint,
    $method,
    $requestBody,
    $responseBody,
    $statusCode
);

$stmt->execute();
$stmt->close();

http_response_code(204);
