<?php
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

/* ==========================
   Bind user
========================== */
$GLOBALS['__QA_USER_ID__'] = $data['user_id'] ?? null;

try {
    $userId = qa_get_user_id();
} catch (Exception $e) {
    http_response_code(403);
    exit;
}

/* ==========================
   Assign iteration
========================== */
$iteration = qa_assign_iteration_id($data['timestamp']);
if ($iteration === null) {
    http_response_code(204);
    exit;
}

$sessionId = qa_get_session_id();

/* ==========================
   Normalize UI logs
========================== */
if (($data['type'] ?? '') === 'frontend-ui' || isset($data['ui_type'])) {
    $data['type'] = 'frontend-io';
    $data['response'] = $data['message'] ?? '[UI message]';
}

/* ==========================
   Insert log
========================== */
$db = qa_db();

$stmt = $db->prepare("
    INSERT INTO qa_logs
    (user_id, session_id, iteration, type, endpoint, method,
     request_body, response_body, status_code, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
");

$type        = $data['type'] ?? 'frontend-io';
$endpoint    = $data['url'] ?? null;
$method      = $data['method'] ?? null;
$requestBody = isset($data['request']) ? json_encode($data['request']) : null;
$responseBody= isset($data['response']) ? json_encode($data['response']) : null;
$statusCode  = $data['status'] ?? 200;

$stmt->bind_param(
    'isisssssi',
    $userId,
    $sessionId,
    $iteration,
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
