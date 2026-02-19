<?php
function loadSessionNamesForViewer(
    PDO $db,
    ?string $program  = null,
    ?string $fromDate = null,
    ?string $toDate   = null,
    ?string $userId   = null
): array {

    if ($program === '') {
        return ['sessions' => [], 'baseQuery' => ''];
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

    $sql = "
        SELECT
            program_name,
            session_id,
            MAX(user_id)     AS user_id,
            MIN(created_at)  AS started_at,
            MAX(created_at)  AS last_updated
        FROM qa_logs
        $where
        GROUP BY session_id, program_name
        ORDER BY MAX(created_at) DESC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $baseQuery = http_build_query([
        'user'      => $program ?? '',
        'from_date' => $fromDate,
        'to_date'   => $toDate,
        'user_id'   => $userId ?? ''
    ]);

    return [
        'sessions'  => $sessions,
        'baseQuery' => $baseQuery
    ];
}
