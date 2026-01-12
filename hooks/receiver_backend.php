<?php

session_start();
$userId = $_SESSION['user']['id'] ?? 'guest';

/* ==========================
   User-specific log folder
========================== */
$logBase = __DIR__ . "/../../logger/logs/user_{$userId}";
if (!is_dir($logBase)) {
    mkdir($logBase, 0777, true); // create folder recursively
}

function qa_format_value($value)
{
    if (is_array($value) || is_object($value)) {
        return $value;
    }

    if (is_string($value)) {
        $trim = trim($value);
        if (($trim[0] ?? '') === '{' || ($trim[0] ?? '') === '[') {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }
        return $value;
    }

    return (string)$value;
}

require_once __DIR__ . '/../../logger/iteration_logic/qa_iteration_helper.php';

$data = json_decode(file_get_contents('php://input'), true);

/* Format request/response/body/etc */
foreach (['request', 'response', 'inputs', 'result', 'body'] as $key) {
    if (array_key_exists($key, $data)) {
        $data[$key] = qa_format_value($data[$key]);
    }
}

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

/* Add meta */
$data['iteration_id'] = $iteration;
$data['session_id']   = $session;
$data['user_id']      = $userId;

/* Write log to user-specific folder */
$file = "{$logBase}/backend_logs_{$session}.jsonl";
file_put_contents($file, json_encode($data) . PHP_EOL, FILE_APPEND);

http_response_code(204);
