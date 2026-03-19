<?php

function syncErrorGroups(PDO $db, string $program, array $errorLogs): void
{
    $grouped = group_error_logs($errorLogs);

    foreach ($grouped as $group) {

        $decoded = json_decode($group['response_body'] ?? '', true);

        $message  = $decoded['message'] ?? '';
        $severity = $decoded['severity'] ?? '';
        $type     = $group['type'] ?? '';

        $groupKey = md5($type . '|' . $message . '|' . $severity);
        $count    = $group['_count'];

        // 🔹 Upsert main issue
        $stmt = $db->prepare("
            IF EXISTS (SELECT 1 FROM qa_error_groups WHERE group_key = ?)
            BEGIN
                UPDATE qa_error_groups
                SET error_count = ?, updated_at = GETDATE()
                WHERE group_key = ?
            END
            ELSE
            BEGIN
                INSERT INTO qa_error_groups
                    (group_key, program_name, error_type, message, severity, error_count)
                VALUES (?, ?, ?, ?, ?, ?)
            END
        ");

        $stmt->execute([
            $groupKey,
            $count,
            $groupKey,

            $groupKey,
            $program,
            $type,
            $message,
            $severity,
            $count
        ]);

        // 🔹 Insert occurrences (important!)
        foreach ($group['_endpoints'] as $endpoint) {
            // If you still have original logs, use them instead
            $stmt = $db->prepare("
                INSERT INTO qa_error_occurrences (group_key, session_id, iteration)
                VALUES (?, ?, ?)
            ");

            $stmt->execute([
                $groupKey,
                $group['session_id'] ?? null,
                $group['iteration'] ?? null
            ]);
        }
    }
}

function loadErrorRemarks(PDO $db, ?string $program = null): array
{
    $whereSql = $program ? "WHERE g.program_name = :program" : "";

    $sql = "
        SELECT 
            g.group_key,
            g.program_name,
            g.error_type,
            g.message,
            g.severity,
            g.error_count,
            g.status,
            g.remark,
            o.session_id,
            o.iteration
        FROM qa_error_groups g
        INNER JOIN qa_error_occurrences o
            ON o.group_key = g.group_key
        $whereSql
        ORDER BY g.error_count DESC, g.updated_at DESC, o.session_id, o.iteration
    ";

    $stmt = $db->prepare($sql);

    if ($program) {
        $stmt->execute([':program' => $program]);
    } else {
        $stmt->execute();
    }

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function updateErrorRemark(
    PDO $db,
    string $groupKey,
    string $status,
    ?string $remark = null
): void {

    $allowed = ['pending', 'standby', 'working', 'resolved'];

    if (!in_array($status, $allowed)) {
        throw new InvalidArgumentException("Invalid status");
    }

    $sql = "
        UPDATE qa_error_groups
        SET status = ?, remark = ?, updated_at = GETDATE()
        WHERE group_key = ?
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([$status, $remark, $groupKey]);
}