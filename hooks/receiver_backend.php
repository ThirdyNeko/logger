<?php
// 🚫 Never log PHP deprecations
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../iteration_logic/qa_iteration_helper.php';

/* ==========================
   Read backend payload
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

/* ==========================
   Assign iteration & session
========================== */
$iteration = qa_assign_iteration_id($data['timestamp']);
if ($iteration === null) {
    http_response_code(204);
    exit;
}

$sessionId = qa_get_session_id();

/* ==========================
   Extract log data
========================== */
$type         = $data['type'] ?? 'backend-response';
$program_name = $data['program_name'] ?? 'UNKNOWN_APP';
$device_name  = $data['device_name'] ?? 'guest';
$endpoint     = $data['endpoint'] ?? null;
$method       = $data['method'] ?? null;
$requestBody  = isset($data['request']) ? json_encode($data['request']) : null;
$responseBody = isset($data['response']) ? json_encode($data['response']) : null;
$statusCode   = $data['status'] ?? 200;

$user_id    = $device_name;
$session_id = $program_name . '_Test_' . $iteration;


/* ==========================
   Insert backend log
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
