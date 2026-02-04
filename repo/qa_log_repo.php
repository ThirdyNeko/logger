<?php
// repo/qa_log_repo.php

class QaLogRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function insertLog(array $data): bool
    {
        $sql = "
            INSERT INTO qa_logs
            (user_id, session_id, iteration, device_name, program_name, type, endpoint, method, request_body, response_body, status_code, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE())
        ";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            $data['user_id'],
            $data['session_id'],
            $data['iteration'],
            $data['device_name'],
            $data['program_name'],
            $data['type'],
            $data['endpoint'],
            $data['method'],
            $data['request_body'],
            $data['response_body'],
            $data['status_code']
        ]);
    }
    
    public function getLatestSessionForProgram(string $program): ?array
    {
        $stmt = $this->db->prepare("
            SELECT TOP 1 session_id, iteration
            FROM qa_logs
            WHERE program_name = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$program]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getPrograms(): array {
        $stmt = $this->db->query("
            SELECT DISTINCT program_name
            FROM qa_logs
            WHERE program_name IS NOT NULL
            ORDER BY program_name ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getSessions(string $program, ?string $from, ?string $to): array
    {
        if ($from && $to) {
            $stmt = $this->db->prepare("
                SELECT DISTINCT session_id, user_id
                FROM qa_logs
                WHERE program_name = ?
                AND created_at >= ?
                AND created_at < DATEADD(day, 1, ?)
                ORDER BY user_id
            ");
            $stmt->execute([$program, $from, $to]);
        } else {
            $stmt = $this->db->prepare("
                SELECT DISTINCT session_id, user_id
                FROM qa_logs
                WHERE program_name = ?
                ORDER BY user_id
            ");
            $stmt->execute([$program]);
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getLogsForIteration(
        string $program,
        string $session,
        int $iteration
    ): array {
        $stmt = $this->db->prepare("
            SELECT *
            FROM qa_logs
            WHERE program_name = ?
              AND session_id = ?
              AND iteration = ?
            ORDER BY created_at ASC
        ");
        $stmt->execute([$program, $session, $iteration]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getErrorIterations(string $program, string $session): array {
        $stmt = $this->db->prepare("
            SELECT DISTINCT iteration
            FROM qa_logs
            WHERE program_name = ?
              AND session_id = ?
              AND type IN ('backend-error','backend-fatal')
        ");
        $stmt->execute([$program, $session]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'iteration');
    }

}
