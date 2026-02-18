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

/*----------------
DEVELOPER VIEWER CODE
HAS SUMMARY FEATURE
-------------------*/

/**
 * Load logs for a program/session.
 * Can load a single iteration or all iterations if $iteration === 'summary'.
 * Optionally attach remark info.
 *
 * @param PDO              $db
 * @param string           $program
 * @param string           $session
 * @param int|string       $iteration Either an integer iteration or 'summary'
 * @param array|null       $remarked Optional remark array [session_id][iteration]
 *
 * @return array<int, array> Log rows, optionally with '_remark_name' and '_remark_text'
 */
function loadLogs(PDO $db, string $program, string $session, int|string $iteration, ?array $remarked = null): array
{
    if ($iteration === 'summary') {
        $sql = "
            SELECT *
            FROM qa_logs
            WHERE program_name = :program_name
              AND session_id = :session_id
            ORDER BY iteration ASC, created_at ASC
        ";

        $params = [
            ':program_name' => $program,
            ':session_id'   => $session
        ];
    } else {
        $sql = "
            SELECT *
            FROM qa_logs
            WHERE program_name = :program_name
              AND session_id = :session_id
              AND iteration = :iteration
            ORDER BY created_at ASC
        ";

        $params = [
            ':program_name' => $program,
            ':session_id'   => $session,
            ':iteration'    => $iteration
        ];
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Attach remark info if available
    if ($remarked) {
        foreach ($logs as &$log) {
            $iter = (int)$log['iteration'];
            $remarkEntry = $remarked[$session][$iter] ?? null;
            if ($remarkEntry) {
                $log['_remark_name'] = $remarkEntry['name'];
                $log['_remark_text'] = $remarkEntry['remark'];
            }
        }
        unset($log);
    }

    return $logs;
}


/*----------------
Branch Code
----------------*/

function loadLogsForViewer(
    PDO $db,
    string $program,
    string $session,
    $iteration,                 // int|string ('summary')
    ?string $branchId,
    ?string $userId,
    ?string $clientIP,
    array $filteredRemarked
): array {
    if (!$program || !$session) {
        return [];
    }

    $params = [
        ':program' => $program,
        ':session' => $session,
    ];

    if ($iteration === 'summary') {
        $sql = "
            SELECT *
            FROM qa_logs
            WHERE program_name = :program
              AND session_id   = :session
        ";
    } else {
        $sql = "
            SELECT *
            FROM qa_logs
            WHERE program_name = :program
              AND session_id   = :session
              AND iteration    = :iteration
        ";

        $params[':iteration'] = (int) $iteration;
    }

    // Optional branch filter
    if (!empty($branchId)) {
        $sql .= " AND branch_id = :branch_id";
        $params[':branch_id'] = $branchId;
    }

    // ✅ Optional user filter
    if (!empty($userId)) {
        $sql .= " AND user_id = :user_id";
        $params[':user_id'] = $userId;
    }

    if (!empty($clientIP)) {
        $sql .= " AND client_ip = :client_ip";
        $params[':client_ip'] = $clientIP;
    }

    $sql .= $iteration === 'summary'
        ? " ORDER BY iteration ASC, created_at ASC"
        : " ORDER BY created_at ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Inject remark info
    foreach ($logs as &$log) {
        $iter = (int) $log['iteration'];
        $remarkEntry = $filteredRemarked[$session][$iter] ?? null;

        if ($remarkEntry) {
            $log['_remark_name'] = $remarkEntry['name'];
            $log['_remark_text'] = $remarkEntry['remark'];
        }
    }
    unset($log);

    return $logs;
}
