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
require_once __DIR__ . '/get_ip.php';
require_once __DIR__ . '/../repo/qa_log_repo.php'; // Repository
require_once __DIR__ . '/user_id.php'; // User ID mapping


/* ==========================
   Read frontend payload
========================== */
$data = json_decode(file_get_contents('php://input'), true);
if (!$data || empty($data['timestamp'])) {
    http_response_code(400);
    exit;
}


$device_name  = qa_get_client_ip();

// Karl 239
// Third 14
// Reil 21
// April 13

/* ==========================
   Dev Team IP (OVERRIDE)
========================== */

$user_id = getUserByIp($device_name, $devMap) ?? "Guest";

$GLOBALS['__QA_USER_ID__']    = $user_id;
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
   Prepare log data
========================== */
$logData = [
    'user_id'       => $user_id,
    'session_id'    => $session_id,
    'iteration'     => $iteration,
    'device_name'   => $device_name,
    'program_name'  => $data['program_name'] ?? 'UNKNOWN_APP',
    'type'          => $data['type'] ?? 'frontend-io',
    'endpoint'      => $data['url'] ?? null,
    'method'        => $data['method'] ?? null,
    'request_body'  => isset($data['request']) ? json_encode($data['request']) : null,
    'response_body' => isset($data['response']) ? json_encode($data['response']) : null,
    'status_code'   => $data['status'] ?? 200
];

/* ==========================
   Insert frontend log via repository
========================== */
$logRepo = new QaLogRepository(qa_db());
$logRepo->insertLog($logData);

http_response_code(204);