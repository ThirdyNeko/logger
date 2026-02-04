<?php
class QaRemarkRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function getRemarks(string $program): array {
        $stmt = $this->db->prepare("
            SELECT session_id, iteration, remark_name, remark, created_at
            FROM qa_remarks
            WHERE program_name = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$program]);

        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[$row['session_id']][(int)$row['iteration']] = [
                'name'   => $row['remark_name'],
                'remark' => $row['remark'],
                'ctime'  => strtotime($row['created_at'])
            ];
        }
        return $out;
    }

    public function saveRemark(array $data): void {
        $stmt = $this->db->prepare("
            MERGE qa_remarks AS t
            USING (SELECT ? AS program_name, ? AS session_id, ? AS iteration) AS s
            ON t.program_name = s.program_name
           AND t.session_id  = s.session_id
           AND t.iteration   = s.iteration
            WHEN MATCHED THEN
                UPDATE SET
                    remark_name = ?,
                    remark      = ?,
                    username    = ?
            WHEN NOT MATCHED THEN
                INSERT (user_id, username, program_name, session_id, iteration, remark_name, remark)
                VALUES (?, ?, ?, ?, ?, ?, ?);
        ");

        $stmt->execute([
            $data['program'],
            $data['session'],
            $data['iteration'],
            $data['remark_name'],
            $data['remark'],
            $data['username'],
            $data['user_id'],
            $data['username'],
            $data['program'],
            $data['session'],
            $data['iteration'],
            $data['remark_name'],
            $data['remark'],
        ]);
    }
}
