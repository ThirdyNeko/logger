<?php
// 🚫 NEVER LOG PHP DEPRECATIONS
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

// Load helpers
require_once __DIR__ . '/../iteration_logic/qa_iteration_helper.php';
require_once __DIR__ . '/../config/db.php'; // adjust path as needed


// Read the POSTed log data
$data = json_decode(file_get_contents('php://input'), true);
$GLOBALS['__QA_USER_ID__'] = $data['user_id'] ?? 'guest';

// Get the user ID safely from global
$userId = $data['user_id'] ?? $GLOBALS['__QA_USER_ID__'] ?? 'guest';

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

// ---------------------------
// Write to database
// ---------------------------
$db = qa_db(); // make sure db.php has your mysqli connection helper

$stmt = $db->prepare("
    INSERT INTO qa_logs
    (user_id, session_id, iteration_id, type, url, method, request_body, response_body, status_code, timestamp)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

$requestJson  = isset($data['request'])  ? json_encode($data['request'], JSON_UNESCAPED_UNICODE)  : null;
$responseJson = isset($data['response']) ? json_encode($data['response'], JSON_UNESCAPED_UNICODE) : null;

$iterationId = (int) $data['iteration_id'];
$statusCode  = isset($data['status']) ? (int)$data['status'] : 200;

$stmt->bind_param(
    'sissssssis',
    $data['user_id'],      // string
    $data['session_id'],   // string
    $iterationId,          // int
    $data['type'] ?? null, // string
    $data['url'] ?? null,  // string
    $data['method'] ?? null,// string
    $requestJson,          // string
    $responseJson,         // string
    $statusCode,           // int
    $data['timestamp']     // string
);


$stmt->execute();
$stmt->close();

http_response_code(204);
