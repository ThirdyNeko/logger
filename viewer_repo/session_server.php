<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/dashboard.php';

$db = qa_db();

$draw   = intval($_POST['draw'] ?? 1);
$start  = intval($_POST['start'] ?? 0);
$length = intval($_POST['length'] ?? 25);

// Build from/to datetime
$fromDate = !empty($_POST['from_date']) ? $_POST['from_date'] : null;
$toDate   = !empty($_POST['to_date'])   ? $_POST['to_date'] : null;

$result = loadSessionNamesForViewer(
    $db,
    $start,
    $length,
    $_POST['user'] ?? null,
    $fromDate,
    $toDate,
    $_POST['user_id'] ?? null
);

$data = [];
foreach ($result['sessions'] as $row) {
    $data[] = [
        $row['program_name'],
        $row['session_id'],
        $row['user_id'],
        date('Y-m-d H:i:s', strtotime($row['last_updated'])), // 👈 format to seconds
        '<a href="#" class="print-session text-decoration-none"><i class="bi bi-printer"></i></a>'
    ];
}

echo json_encode([
    "draw" => $draw,
    "recordsTotal" => $result['recordsFiltered'], // ideally total sessions without filters
    "recordsFiltered" => $result['recordsFiltered'],
    "data" => $data
]);