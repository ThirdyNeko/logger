<?php

/**
 * Get all iterations that have backend errors/fatals for a given program and session.
 *
 * @param PDO    $db
 * @param string $program
 * @param string $session
 *
 * @return array<int, bool> iterations as keys, value always true
 */
/**
 * Get all iterations for a program (optionally by session and date range)
 */
function getAllIterations(PDO $db, string $program, ?string $session = null, ?string $fromDate = null, ?string $toDate = null): array
{
    $sql = "
        SELECT DISTINCT iteration, session_id
        FROM qa_logs
        WHERE program_name = :program_name
    ";

    $params = [':program_name' => $program];

    if ($session) {
        $sql .= " AND session_id = :session_id";
        $params[':session_id'] = $session;
    }

    if ($fromDate && $toDate) {
        $sql .= " AND CONVERT(DATE, created_at) BETWEEN :from_date AND :to_date";
        $params[':from_date'] = $fromDate;
        $params[':to_date'] = $toDate;
    }

    $sql .= " ORDER BY iteration ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $iterations = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $iterations[] = (int)$row['iteration'];
    }

    return array_unique($iterations);
}

/**
 * Get iterations with errors for a program (optionally by session)
 */
function getErrorIterations(PDO $db, string $program, ?string $session = null): array
{
    $sql = "
        SELECT DISTINCT iteration
        FROM qa_logs
        WHERE program_name = :program_name
          AND type IN ('backend-error', 'backend-fatal')
    ";

    $params = [':program_name' => $program];

    if ($session) {
        $sql .= " AND session_id = :session_id";
        $params[':session_id'] = $session;
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $errorIterations = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $errorIterations[(int)$row['iteration']] = true;
    }

    return $errorIterations;
}
