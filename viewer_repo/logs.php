<?php

/**
 * Load all logs for a selected program/session/iteration.
 * Optionally attach remark info if provided.
 *
 * @param PDO   $db
 * @param string $program
 * @param string $session
 * @param int    $iteration
 * @param array|null $remarked Optional preloaded remark array [session_id][iteration]
 *
 * @return array<int, array> Each log row, optionally with '_remark_name' and '_remark_text'
 */
function loadLogsForIteration(
    PDO $db,
    string $program,
    string $session,
    int $iteration,
    ?array $remarked = null
): array {
    $sql = "
        SELECT *
        FROM qa_logs
        WHERE program_name = :program_name
          AND session_id = :session_id
          AND iteration = :iteration
        ORDER BY created_at ASC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':program_name' => $program,
        ':session_id'   => $session,
        ':iteration'    => $iteration
    ]);

    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Attach remark info if available
    if ($remarked && isset($remarked[$session][$iteration])) {
        $remarkEntry = $remarked[$session][$iteration];
        foreach ($logs as &$log) {
            $log['_remark_name'] = $remarkEntry['name'];
            $log['_remark_text'] = $remarkEntry['remark'];
        }
        unset($log);
    }

    return $logs;
}

/**
 * Get the latest log for a given program.
 *
 * @param PDO    $db
 * @param string $program
 *
 * @return array{session_id: string, iteration: int}
 */
function getLatestLog(PDO $db, string $program): array
{
    $latestLog = [
        'session_id' => '',
        'iteration'  => 0
    ];

    $sql = "
        SELECT TOP 1 session_id, iteration
        FROM qa_logs
        WHERE program_name = :program_name
        ORDER BY created_at DESC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([':program_name' => $program]);

    if ($res = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $latestLog = [
            'session_id' => $res['session_id'],
            'iteration'  => (int)$res['iteration']
        ];
    }

    return $latestLog;
}