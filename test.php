
<?php
require_once __DIR__ . '/config/db.php';

$db = qa_db();

$data = [
    'user_id' => 1,
    'session_id' => 'TEST_SESSION',
    'iteration_id' => 1,
    'type' => 'backend-test',
    'url' => '/test',
    'method' => 'GET',
    'request_body' => '{"foo":"bar"}',
    'response_body' => '{"ok":true}',
    'status_code' => 200,
    'timestamp' => date('Y-m-d H:i:s')
];

$stmt = $db->prepare("
    INSERT INTO qa_logs
    (user_id, session_id, iteration_id, type, url, method, request_body, response_body, status_code, timestamp)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->bind_param(
    'sissssssis',
    $data['user_id'],
    $data['session_id'],
    $data['iteration_id'],
    $data['type'],
    $data['url'],
    $data['method'],
    $data['request_body'],
    $data['response_body'],
    $data['status_code'],
    $data['timestamp']
);

if ($stmt->execute()) {
    echo "✅ Inserted successfully!";
} else {
    echo "❌ Insert failed: " . $stmt->error;
}
$stmt->close();
