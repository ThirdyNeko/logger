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
}
