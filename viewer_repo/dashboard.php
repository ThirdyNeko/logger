<?php
function loadSessionNamesForViewer(
    PDO $db,
    ?string $program      = null,
    ?string $fromDate = null,
    ?string $toDate  = null,
    ?string $userId       = null,
    int $limit = 50,
    int $offset = 0
): array {

    if ($program === '') {
        return ['sessions' => [], 'total' => 0, 'baseQuery' => ''];
    }

    $params = [];
    $where = " WHERE 1=1 ";

    if ($program) {
        $where .= " AND program_name = :program ";
        $params[':program'] = $program;
    }

    if ($fromDate) {
        $where .= " AND created_at >= :fromDate ";
        $params[':fromDate'] = $fromDate . ' 00:00:00';
    }

    if ($toDate) {
        $where .= " AND created_at <= :toDate ";
        $params[':toDate'] = $toDate . ' 23:59:59';
    }

    if ($userId) {
        $where .= " AND user_id LIKE :userId ";
        $params[':userId'] = '%' . $userId . '%';
    }


    // Total sessions for pagination
    $countStmt = $db->prepare("SELECT COUNT(DISTINCT session_id) FROM qa_logs $where");
    $countStmt->execute($params);
    $totalSessions = (int)$countStmt->fetchColumn();

    // Base query for pagination links
    $baseQuery = http_build_query([
        'user'      => $program ?? '',
        'from_date' => $fromDate,
        'to_date'   => $toDate,
        'user_id'   => $userId ?? ''
    ]);

    // Fetch paginated sessions using SQL Server syntax
    $sql = "
        SELECT *
        FROM (
            SELECT
                program_name,
                session_id,
                MAX(user_id)   AS user_id,
                MIN(created_at) AS started_at,
                MAX(created_at) AS last_updated,
                ROW_NUMBER() OVER (ORDER BY MAX(created_at) DESC) AS row_num
            FROM qa_logs
            $where
            GROUP BY session_id, program_name
        ) AS sub
        WHERE row_num BETWEEN :startRow AND :endRow
        ORDER BY last_updated DESC
    ";

    $stmt = $db->prepare($sql);

    // Bind filters
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }

    // Bind pagination rows
    $stmt->bindValue(':startRow', $offset + 1, PDO::PARAM_INT);
    $stmt->bindValue(':endRow', $offset + $limit, PDO::PARAM_INT);

    $stmt->execute();
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'sessions' => $sessions,
        'total'    => $totalSessions,
        'baseQuery'=> $baseQuery
    ];
}
