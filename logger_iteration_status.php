<?php
require_once __DIR__ . '/qa_iteration_helper.php';

$status = qa_get_logging_status();

header('Content-Type: application/json');
echo json_encode([
    'iteration' => $status['iteration'],
    'active'    => $status['active']
]);