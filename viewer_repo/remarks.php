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
        SELECT session_id, iteration, remark_name, remark, username, created_at,
               resolved, resolved_by, resolved_at, resolve_comment
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
            'name'            => $row['remark_name'],
            'remark'          => $row['remark'],
            'username'        => $row['username'] ?? 'Unknown',
            'ctime'           => strtotime($row['created_at']),
            'resolved'        => (bool) $row['resolved'],
            'resolved_by'     => $row['resolved_by'] ?? null,
            'resolved_at'     => $row['resolved_at'] ?? null,
            'resolve_comment' => $row['resolve_comment'] ?? null
        ];
    }

    return $remarked;
}


/**
 * Load paginated QA remarks with filters.
 *
 * @return array{
 *     data: array<int, array>,
 *     total: int
 * }
 */
function loadRemarksPaginated(
    PDO $db,
    ?string $program,
    ?string $username,
    ?string $status,      // 'resolved' | 'pending' | null
    ?string $fromDate,
    ?string $toDate,
    int $perPage,
    int $offset
): array {

    $where = [];
    $params = [];

    if ($program) {
        $where[] = "program_name LIKE :program";
        $params[':program'] = "%$program%";
    }

    if ($username) {
        $where[] = "username LIKE :username";
        $params[':username'] = "%$username%";
    }

    if ($status !== null && $status !== '') {
        $where[] = "resolved = :resolved";
        $params[':resolved'] = $status === 'resolved' ? 1 : 0;
    }

    if ($fromDate) {
        $where[] = "created_at >= :from_date";
        $params[':from_date'] = $fromDate . " 00:00:00";
    }

    if ($toDate) {
        $where[] = "created_at <= :to_date";
        $params[':to_date'] = $toDate . " 23:59:59";
    }

    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

    /* ==========================
       TOTAL COUNT
    ========================== */

    $countSql = "SELECT COUNT(*) FROM qa_remarks $whereSql";
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    /* ==========================
       PAGINATED DATA (ROW_NUMBER)
    ========================== */

    $sql = "
        SELECT *
        FROM (
            SELECT 
                *,
                ROW_NUMBER() OVER (ORDER BY created_at DESC) AS row_num
            FROM qa_remarks
            $whereSql
        ) AS numbered
        WHERE row_num BETWEEN :start AND :end
    ";

    $stmt = $db->prepare($sql);

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $start = $offset + 1;
    $end   = $offset + $perPage;

    $stmt->bindValue(':start', $start, PDO::PARAM_INT);
    $stmt->bindValue(':end', $end, PDO::PARAM_INT);

    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'data'  => $data,
        'total' => $total
    ];
}

/**
 * Mark a QA remark as resolved, adding resolved info.
 */
function markRemarkResolved(
    PDO $db,
    string $program,
    string $sessionId,
    int $iteration,
    string $resolvedBy,
    string $resolveComment
): void {
    $resolvedAt = date('Y-m-d H:i:s');

    $sql = "
        UPDATE qa_remarks
        SET resolved = 1,
            resolved_by = ?,
            resolved_at = ?,
            resolve_comment = ?
        WHERE program_name = ? AND session_id = ? AND iteration = ?
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([
        $resolvedBy,
        $resolvedAt,
        $resolveComment,
        $program,
        $sessionId,
        $iteration
    ]);
}
