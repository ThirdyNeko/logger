<?php
// 🚫 Never log PHP deprecations
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../iteration_logic/qa_iteration_helper.php';
require_once __DIR__ . '/../repo/qa_log_repo.php';
require_once __DIR__ . '/user_id.php'; // User ID mapping

/* ==========================
   Read backend payload
========================== */
$data = json_decode(file_get_contents('php://input'), true);
if (!$data || empty($data['timestamp'])) {
    http_response_code(400);
    exit;
}

// Karl 239
// Third 14
// Reil 21
// April 13

/* ==========================
   Dev Team IP (OVERRIDE)
========================== */

$device_name  = $data['device_name'] ?? 'guest';
$user_id = getUserByIp($device_name, $devMap) ?? "Guest";

$GLOBALS['__QA_PROGRAM__']   = $data['program_name'];
$GLOBALS['__QA_USER_ID__']    = $user_id;
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
   Extract log data
========================== */
$logData = [
    'user_id'       => $user_id,
    'session_id'    => $session_id,
    'iteration'     => $iteration,
    'device_name'   => $device_name,
    'program_name'  => $data['program_name'] ?? 'UNKNOWN_APP',
    'type'          => $data['type'] ?? 'backend-response',
    'endpoint'      => $data['endpoint'] ?? null,
    'method'        => $data['method'] ?? null,
    'request_body'  => isset($data['request']) ? json_encode($data['request']) : null,
    'response_body' => isset($data['response']) ? json_encode($data['response']) : null,
    'status_code'   => $data['status'] ?? 200
];

/* ==========================
   Insert backend log via repository
========================== */
$logRepo = new QaLogRepository(qa_db());
$logRepo->insertLog($logData);

/* ==========================
   REAL-TIME ERROR GROUP SYNC
========================== */
if ($logData['type'] === 'backend-error') {

    $decoded = json_decode($logData['response_body'] ?? '', true);

    $message  = $decoded['message'] ?? '';
    $severity = $decoded['severity'] ?? '';
    $type     = $logData['type'];

    $groupKey = md5($type . '|' . $message . '|' . $severity);

    $db = qa_db();

    // 🔹 Upsert main group
    $stmt = $db->prepare("
        IF EXISTS (SELECT 1 FROM qa_error_groups WHERE group_key = ?)
        BEGIN
            UPDATE qa_error_groups
            SET error_count = error_count + 1,
                updated_at = GETDATE()
            WHERE group_key = ?
        END
        ELSE
        BEGIN
            INSERT INTO qa_error_groups
                (group_key, program_name, error_type, message, severity, error_count)
            VALUES (?, ?, ?, ?, ?, 1)
        END
    ");

    $stmt->execute([
        $groupKey,
        $groupKey,

        $groupKey,
        $logData['program_name'],
        $type,
        $message,
        $severity
    ]);

    // 🔹 Insert occurrence (no duplicates)
    $stmt = $db->prepare("
        IF NOT EXISTS (
            SELECT 1 FROM qa_error_occurrences
            WHERE group_key = ?
              AND session_id = ?
              AND iteration = ?
        )
        INSERT INTO qa_error_occurrences (group_key, session_id, iteration)
        VALUES (?, ?, ?)
    ");

    $stmt->execute([
        $groupKey,
        $session_id,
        $iteration,

        $groupKey,
        $session_id,
        $iteration
    ]);
}

http_response_code(204);
