<?php

session_start();
$userId = $_SESSION['user']['id'] ?? 'guest';

require_once __DIR__ . '/../../logger/iteration_logic/qa_iteration_helper.php';
require_once __DIR__ . '/../config/db.php'; // adjust path as needed


$data = json_decode(file_get_contents('php://input'), true);

/* Validate input */
if (!$data || empty($data['timestamp'])) {
    http_response_code(400);
    exit;
}

/* Assign iteration & session */
$iteration = qa_assign_iteration_id($data['timestamp']);
if ($iteration === null) {
    http_response_code(204);
    exit; // logging stopped
}

$session = qa_get_session_id();

/* Add server-side meta info */
$data['iteration_id'] = $iteration;
$data['session_id']   = $session;
$data['user_id']      = $userId;

/* ==========================
   Normalize logs into frontend-io
========================= */

// If the log is UI type, convert to frontend-io
if (($data['type'] ?? '') === 'frontend-ui' || isset($data['ui_type'])) {
    $data['type'] = 'frontend-io';

    // Use the UI message as the response
    $data['response'] = $data['message'] ?? '[UI message]';

    // Fill missing network context from session, if available
    $data['url'] = $data['url'] ?? ($_SESSION['__QA_LAST_URL__'] ?? null);
    $data['method'] = $data['method'] ?? ($_SESSION['__QA_LAST_METHOD__'] ?? null);
    $data['request'] = $data['request'] ?? ($_SESSION['__QA_LAST_REQUEST__'] ?? null);

    // Clean up old UI fields
    unset($data['message'], $data['ui_type']);
}

/* ==========================
   Save last request for UI context
========================= */
if (($data['type'] ?? '') === 'frontend-io' && !empty($data['url'])) {
    $_SESSION['__QA_LAST_URL__'] = $data['url'];
    $_SESSION['__QA_LAST_METHOD__'] = $data['method'] ?? 'POST';
    $_SESSION['__QA_LAST_REQUEST__'] = $data['request'] ?? null;
}

/* ==========================
   Merge all original data (no loss)
========================= */
$logEntry = $data;

// Enforce required fields
$logEntry['type']      = $data['type'] ?? 'frontend-io';
$logEntry['url']       = $data['url'] ?? null;
$logEntry['method']    = $data['method'] ?? null;
$logEntry['status']    = $data['status'] ?? 200; // default to 200
$logEntry['timestamp'] = $data['timestamp'];
$logEntry['iteration_id'] = $iteration;
$logEntry['session_id']   = $session;
$logEntry['user_id']      = $userId;

// Normalize legacy output field
if (isset($logEntry['output']) && !isset($logEntry['response'])) {
    $logEntry['response'] = $logEntry['output'];
    unset($logEntry['output']);
}

/* ==========================
   Insert log into database
========================= */


$db = qa_db(); // your mysqli connection helper

$stmt = $db->prepare("
    INSERT INTO qa_logs
    (user_id, session_id, iteration_id, type, url, method, request_body, response_body, status_code, timestamp)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

$requestJson  = isset($data['request'])  ? json_encode($data['request'], JSON_UNESCAPED_UNICODE)  : null;
$responseJson = isset($data['response']) ? json_encode($data['response'], JSON_UNESCAPED_UNICODE) : null;

$stmt->bind_param(
    'sissssssis',
    $userId,
    $data['session_id'],
    $data['iteration_id'],
    $data['type'] ?? null,
    $data['url'] ?? null,
    $data['method'] ?? null,
    $requestJson,
    $responseJson,
    $data['status'] ?? null,
    $data['timestamp']
);

$stmt->execute();
$stmt->close();

http_response_code(204);

