<?php
function loadSessionNamesForViewer(
    PDO $db,
    int $start = 0,
    int $length = 25,
    ?string $program = null,
    ?string $fromDate = null,
    ?string $toDate = null,
    ?string $userId = null
): array {

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
        $params[':userId'] = "%$userId%";
    }

    // -----------------------------
    // 1️⃣ Total filtered count
    // -----------------------------
    $countSql = "
        SELECT COUNT(*) AS totalCount
        FROM (
            SELECT session_id, program_name
            FROM qa_logs
            $where
            GROUP BY session_id, program_name
        ) AS grouped_sessions
    ";
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $totalFiltered = (int)$stmt->fetchColumn();

    // -----------------------------
    // 2️⃣ Main query with pagination (SQL Server compatible)
    // -----------------------------
    $sql = "
        SELECT *
        FROM (
            SELECT
                program_name,
                session_id,
                MAX(user_id)   AS user_id,
                MIN(created_at) AS started_at,
                MAX(created_at) AS last_updated,
                ROW_NUMBER() OVER (ORDER BY MAX(created_at) DESC) AS rn
            FROM qa_logs
            $where
            GROUP BY session_id, program_name
        ) AS numbered
        WHERE rn BETWEEN :start_plus_one AND :end
        ORDER BY rn
    ";

    $stmt = $db->prepare($sql);

    // Bind filter params
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    // SQL Server pagination
    $stmt->bindValue(':start_plus_one', $start + 1, PDO::PARAM_INT);
    $stmt->bindValue(':end', $start + $length, PDO::PARAM_INT);

    $stmt->execute();
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'sessions' => $sessions,
        'recordsFiltered' => $totalFiltered
    ];
}