<?php

function qa_format_value($value)
{
    // Already structured
    if (is_array($value) || is_object($value)) {
        return $value;
    }

    // JSON string masquerading as text
    if (is_string($value)) {
        $trim = trim($value);
        if (
            ($trim[0] ?? '') === '{' ||
            ($trim[0] ?? '') === '['
        ) {
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

foreach (['request', 'response', 'inputs', 'result', 'body'] as $key) {
    if (array_key_exists($key, $data)) {
        $data[$key] = qa_format_value($data[$key]);
    }
}


if (!$data || empty($data['timestamp'])) {
    http_response_code(400);
    exit;
}




$iteration = qa_assign_iteration_id($data['timestamp']);
if ($iteration === null) {
    http_response_code(204);
    exit; // logging stopped
}

$session = qa_get_session_id();

$data['iteration_id'] = $iteration;
$data['session_id']   = $session;

$file = __DIR__ . "/../../logger/logs/backend_logs_{$session}.jsonl";

file_put_contents($file, json_encode($data) . PHP_EOL, FILE_APPEND);

http_response_code(204);
