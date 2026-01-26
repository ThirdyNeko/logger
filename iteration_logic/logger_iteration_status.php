<?php
require_once __DIR__ . '/../config/db.php';

$program = $_GET['program'] ?? '';
$session = $_GET['session'] ?? '';

$response = ['iteration' => 0, 'active' => false];

if ($program && $session) {
    $db = qa_db();

    // Get the max iteration for the selected program & session
    $stmt = $db->prepare("
        SELECT MAX(iteration) AS max_iter
        FROM qa_logs
        WHERE program_name = ? AND session_id = ?
    ");
    $stmt->bind_param('ss', $program, $session);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $response['iteration'] = (int)($res['max_iter'] ?? 0);
    $response['active'] = $response['iteration'] > 0;
}

header('Content-Type: application/json');
echo json_encode($response);
