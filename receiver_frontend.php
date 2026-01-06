<?php
require_once __DIR__ . '/qa_iteration_helper.php';

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || empty($data['timestamp'])) {
    http_response_code(400);
    exit;
}

$iteration = qa_assign_iteration_id($data['timestamp']);
if ($iteration === null) {
    http_response_code(204);
    exit;
}

$session = qa_get_session_id();

$data['iteration_id'] = $iteration;
$data['session_id']   = $session;

$file = __DIR__ . "/logs/frontend_logs_{$session}.jsonl";

file_put_contents($file, json_encode($data) . PHP_EOL, FILE_APPEND);

http_response_code(204);
