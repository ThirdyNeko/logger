<?php

/**
 * Get a list of all programs from logs.
 *
 * @return array<string, string> program_name => display_name
 */
function loadPrograms(PDO $db): array
{
    $sql = "
        SELECT DISTINCT program_name
        FROM qa_logs
        WHERE program_name IS NOT NULL
        ORDER BY program_name ASC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute();

    $programs = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $name = $row['program_name'];
        $programs[$name] = $name ?: "Unknown Program ({$name})";
    }

    return $programs;
}
