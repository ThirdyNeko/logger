<?php

/**
 * Insert or update a QA remark for a user + session + iteration.
 */
function saveQaRemark(
    PDO $db,
    int $userId,
    string $username,
    string $program,
    string $sessionId,
    int $iteration,
    string $remarkName,
    string $remark,
    bool $resolved
    
): void {
    $sql = "
        IF EXISTS (
            SELECT 1
            FROM qa_remarks
            WHERE user_id = ?
              AND program_name = ?
              AND session_id = ?
              AND iteration = ?
        )
        BEGIN
            UPDATE qa_remarks
            SET
                remark_name = ?,
                remark = ?,
                username = ?,
                resolved = ?
            WHERE user_id = ?
              AND program_name = ?
              AND session_id = ?
              AND iteration = ?
        END
        ELSE
        BEGIN
            INSERT INTO qa_remarks
                (user_id, username, program_name, session_id, iteration, remark_name, remark, resolved)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        END
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([
        // EXISTS
        $userId,
        $program,
        $sessionId,
        $iteration,

        // UPDATE
        $remarkName,
        $remark,
        $username,
        $resolved,
        $userId,
        $program,
        $sessionId,
        $iteration,
        

        // INSERT
        $userId,
        $username,
        $program,
        $sessionId,
        $iteration,
        $remarkName,
        $remark,
        $resolved
    ]);
}

/**
 * Load all remarks for a given program, organized by session and iteration.
 *
 * @return array<string, array<int, array{name:string,remark:string,ctime:int}>>
 *         Format: $remarked[session_id][iteration] = ['name'=>..., 'remark'=>..., 'ctime'=>...]
 */
function loadRemarksByProgram(PDO $db, string $program): array
{
    $sql = "
        SELECT session_id, iteration, remark_name, remark, username, created_at, resolved
        FROM qa_remarks
        WHERE program_name = :program_name
        ORDER BY created_at DESC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([':program_name' => $program]);

    $remarked = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $sid  = $row['session_id'];
        $iter = (int) $row['iteration'];

        $remarked[$sid][$iter] = [
            'name'     => $row['remark_name'],
            'remark'   => $row['remark'],
            'username' => $row['username'] ?? 'Unknown', // ✅ add username
            'ctime'    => strtotime($row['created_at']),
            'resolved' => (bool) $row['resolved']
        ];
    }

    return $remarked;
}

