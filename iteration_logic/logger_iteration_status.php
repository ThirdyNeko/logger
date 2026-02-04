<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../repo/qa_log_repo.php';

$program = $_GET['program'] ?? '';

$response = [
    'latestSession' => '',
    'latestIteration' => 0,
    'active' => false
];

if ($program) {
    $logRepo = new QaLogRepository(qa_db());
    $latest = $logRepo->getLatestSessionForProgram($program);

    if ($latest) {
        $response['latestSession'] = $latest['session_id'];
        $response['latestIteration'] = (int)$latest['iteration'];
        $response['active'] = true;
    }
}

header('Content-Type: application/json');
echo json_encode($response);
