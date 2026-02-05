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
function getErrorIterations(PDO $db, string $program, string $session): array
{
    $sql = "
        SELECT DISTINCT iteration
        FROM qa_logs
        WHERE program_name = :program_name
          AND session_id = :session_id
          AND type IN ('backend-error', 'backend-fatal')
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':program_name' => $program,
        ':session_id'   => $session
    ]);

    $errorIterations = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $errorIterations[(int)$row['iteration']] = true;
    }

    return $errorIterations;
}

/**
 * Get all iterations for a given program/session,
 * optionally filtered by date range.
 *
 * @param PDO    $db
 * @param string $program
 * @param string $session
 * @param string|null $fromDate YYYY-MM-DD or null
 * @param string|null $toDate   YYYY-MM-DD or null
 *
 * @return array<int> sorted list of iterations
 */
function getAllIterations(PDO $db, string $program, string $session, ?string $fromDate = null, ?string $toDate = null): array
{
    $sql = "
        SELECT DISTINCT iteration
        FROM qa_logs
        WHERE program_name = :program_name
          AND session_id = :session_id
          AND (
                (:from_date_empty IS NULL OR :to_date_empty IS NULL)
                OR CONVERT(DATE, created_at) BETWEEN :from_date_val AND :to_date_val
              )
        ORDER BY iteration ASC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':program_name'    => $program,
        ':session_id'      => $session,
        ':from_date_empty' => $fromDate,
        ':to_date_empty'   => $toDate,
        ':from_date_val'   => $fromDate,
        ':to_date_val'     => $toDate
    ]);

    $iterations = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $iterations[] = (int)$row['iteration'];
    }

    sort($iterations);

    return $iterations;
}


function getAllIterationsMergedWithErrors(PDO $db, string $program, string $session, ?string $fromDate = null, ?string $toDate = null): array
{
    $iterations = getAllIterations($db, $program, $session, $fromDate, $toDate);
    $errorIterations = getErrorIterations($db, $program, $session);

    foreach ($errorIterations as $iter => $_) {
        if (!in_array($iter, $iterations, true)) {
            $iterations[] = $iter;
        }
    }

    sort($iterations);
    return $iterations;
}
