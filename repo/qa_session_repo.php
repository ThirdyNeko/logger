<?php
// repo/qa_session_repo.php

class QaSessionRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // Count all sessions for a program
    public function countSessionsForProgram(string $program): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) AS cnt
            FROM qa_session_state
            WHERE program_name = ?
        ");
        $stmt->execute([$program]);
        return (int)$stmt->fetchColumn();
    }

    // Get most recent session for user+program
    public function getLatestSession(string $userId, string $program): ?array
    {
        $stmt = $this->db->prepare("
            SELECT TOP 1 session_id, iteration, remarks_iteration, last_second, program_name
            FROM qa_session_state
            WHERE user_id = ? AND program_name = ?
            ORDER BY CAST(RIGHT(session_id, CHARINDEX('_', REVERSE(session_id)) - 1) AS INT) DESC
        ");
        $stmt->execute([$userId, $program]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // Save or update session (UPSERT)
    public function saveSession(array $state, string $userId): bool
    {
        $program = $state['program_name'] ?? 'UNKNOWN_APP';

        $sql = "
            MERGE qa_session_state AS target
            USING (SELECT ? AS user_id, ? AS session_id) AS source
            ON (target.user_id = source.user_id AND target.session_id = source.session_id)
            WHEN MATCHED THEN
                UPDATE SET iteration = ?, remarks_iteration = ?, last_second = ?, program_name = ?
            WHEN NOT MATCHED THEN
                INSERT (user_id, session_id, iteration, remarks_iteration, last_second, program_name)
                VALUES (?, ?, ?, ?, ?, ?);
        ";

        $stmt = $this->db->prepare($sql);

        $params = [
            $userId, $state['session_id'], // MERGE source
            $state['iteration'], $state['remarks_iteration'], $state['last_second'], $program, // UPDATE
            $userId, $state['session_id'], $state['iteration'], $state['remarks_iteration'], $state['last_second'], $program // INSERT
        ];

        return $stmt->execute($params);
    }
}
