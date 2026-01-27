<?php
require_once __DIR__ . '/../config/db.php';

$program = $_GET['program'] ?? '';

$response = [
    'latestSession' => '',
    'latestIteration' => 0,
    'active' => false
];

if ($program) {
    $db = qa_db();

    $stmt = $db->prepare("
        SELECT session_id, iteration
        FROM qa_logs
        WHERE program_name = ?
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->bind_param('s', $program);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($res) {
        $response['latestSession'] = $res['session_id'];
        $response['latestIteration'] = (int)$res['iteration'];
        $response['active'] = true;
    }
}

header('Content-Type: application/json');
echo json_encode($response);
