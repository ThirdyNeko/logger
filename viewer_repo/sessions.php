<?php

function getLatestSessionByProgram(PDO $db, string $program): ?string
{
    $sql = "
        SELECT TOP 1 session_id
        FROM qa_logs
        WHERE program_name = :program_name
        ORDER BY created_at DESC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([':program_name' => $program]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ? $row['session_id'] : null;
}

function isActiveSession(
    ?string $selectedProgram,
    ?string $selectedSession,
    ?string $latestSession
): bool {
    return
        !empty($selectedProgram) &&
        !empty($selectedSession) &&
        $latestSession !== null &&
        $selectedSession === $latestSession;
}

function saveSessionName(
    PDO $db,
    string $program,
    string $sessionId,
    string $sessionName
): void {
    $sql = "
        IF EXISTS (
            SELECT 1
            FROM qa_session_names
            WHERE program_name = ?
              AND session_id = ?
        )
        BEGIN
            UPDATE qa_session_names
            SET session_name = ?
            WHERE program_name = ?
              AND session_id = ?
        END
        ELSE
        BEGIN
            INSERT INTO qa_session_names (program_name, session_id, session_name)
            VALUES (?, ?, ?)
        END
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([
        $program,
        $sessionId,
        $sessionName,
        $program,
        $sessionId,
        $program,
        $sessionId,
        $sessionName
    ]);
}

/**
 * Load session names for a program.
 *
 * @return array<string, string> session_id => session_name
 */
function loadSessionNames(PDO $db, string $program): array
{
    $sql = "
        SELECT session_id, session_name
        FROM qa_session_names
        WHERE program_name = :program_name
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([':program_name' => $program]);

    $sessionNames = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $sessionNames[$row['session_id']] = $row['session_name'];
    }

    return $sessionNames;
}

/**
 * Load all sessions for a given program, optionally filtered by date range.
 *
 * @param PDO         $db
 * @param string      $program
 * @param string|null $fromDate YYYY-MM-DD or null
 * @param string|null $toDate   YYYY-MM-DD or null
 *
 * @return array{
 *   sessions: string[],           // list of session_ids
 *   sessionOwners: array<string,string> // session_id => user_id
 * }
 */
function loadSessions(PDO $db, string $program, ?string $fromDate = null, ?string $toDate = null): array
{
    if ($fromDate && $toDate) {
        $sql = "
            SELECT DISTINCT session_id, user_id
            FROM qa_logs
            WHERE program_name = :program_name
              AND CONVERT(DATE, created_at) BETWEEN :from_date AND :to_date
            ORDER BY user_id ASC
        ";
        $params = [
            ':program_name' => $program,
            ':from_date'    => $fromDate,
            ':to_date'      => $toDate
        ];
    } else {
        $sql = "
            SELECT DISTINCT session_id, user_id
            FROM qa_logs
            WHERE program_name = :program_name
            ORDER BY user_id ASC
        ";
        $params = [
            ':program_name' => $program
        ];
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $sessions = [];
    $sessionOwners = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $sessions[] = $row['session_id'];
        $sessionOwners[$row['session_id']] = $row['user_id'] ?? 'Unknown';
    }

    return [
        'sessions'      => $sessions,
        'sessionOwners' => $sessionOwners
    ];
}


/*----------------
DEVELOPER VIEWER CODE
HAS SUMMARY FEATURE
-------------------*/

/**
 * Fetch sessions for a given program, optionally filtered by date range.
 * Can also handle "summary" mode by ignoring date filters.
 *
 * @param PDO         $db
 * @param string      $program
 * @param string|null $fromDate YYYY-MM-DD or null
 * @param string|null $toDate   YYYY-MM-DD or null
 * @param bool        $summary  If true, ignore date filtering
 *
 * @return array{
 *   sessions: string[],           // list of session_ids
 *   sessionOwners: array<string,string> // session_id => user_id
 * }
 */
function loadSessionsSummary(PDO $db, string $program, ?string $fromDate = null, ?string $toDate = null, bool $summary = false): array
{
    if (!$summary && $fromDate && $toDate) {
        $sql = "
            SELECT DISTINCT session_id, user_id
            FROM qa_logs
            WHERE program_name = :program_name
              AND CONVERT(DATE, created_at) BETWEEN :from_date AND :to_date
            ORDER BY user_id ASC
        ";
        $params = [
            ':program_name' => $program,
            ':from_date'    => $fromDate,
            ':to_date'      => $toDate
        ];
    } else {
        // Summary mode or no date filters
        $sql = "
            SELECT DISTINCT session_id, user_id
            FROM qa_logs
            WHERE program_name = :program_name
            ORDER BY user_id ASC
        ";
        $params = [
            ':program_name' => $program
        ];
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $sessions = [];
    $sessionOwners = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $sessions[] = $row['session_id'];
        $sessionOwners[$row['session_id']] = $row['user_id'] ?? 'Unknown';
    }

    return [
        'sessions'      => $sessions,
        'sessionOwners' => $sessionOwners
    ];
}