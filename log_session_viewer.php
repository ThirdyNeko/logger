<?php
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/qa_iteration_helper.php';

$sessionId = qa_get_session_id();

/* ============================
   FIND PREVIOUS SESSIONS
============================ */

$remarkFiles = glob(__DIR__ . '/logs/remarked_logs_*.json');

$previousSessions = [];

foreach ($remarkFiles as $file) {
    $sid = str_replace(
        ['remarked_logs_', '.json'],
        '',
        basename($file)
    );

    // exclude current session
    if ($sid !== $sessionId) {
        $previousSessions[$sid] = $file;
    }
}

krsort($previousSessions); // newest first

$selectedSession = $_GET['session'] ?? '';
$remarks = [];

if ($selectedSession && isset($previousSessions[$selectedSession])) {
    $remarks = json_decode(
        file_get_contents($previousSessions[$selectedSession]),
        true
    ) ?? [];
}


