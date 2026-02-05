<?php

function getUserIdByUsername(PDO $db, string $username): ?int
{
    $sql = "SELECT TOP 1 id FROM users WHERE username = :username";

    $stmt = $db->prepare($sql);
    $stmt->execute([':username' => $username]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ? (int) $row['id'] : null;
}
