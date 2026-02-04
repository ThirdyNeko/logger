<?php
class UserRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function findByUsername(string $username): ?array {
        $stmt = $this->db->prepare("
            SELECT TOP 1 id, username, password_hash, role, first_login
            FROM users
            WHERE username = ?
        ");
        $stmt->execute([$username]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function usernameExists(string $username): bool {
        $stmt = $this->db->prepare("
            SELECT TOP 1 id FROM users WHERE username = ?
        ");
        $stmt->execute([$username]);
        return (bool) $stmt->fetch();
    }

    public function createUser(string $username, string $hash, string $role): bool {
        $stmt = $this->db->prepare("
            INSERT INTO users (username, password_hash, role, first_login)
            VALUES (?, ?, ?, 1)
        ");
        return $stmt->execute([$username, $hash, $role]);
    }

    // Update user password and first_login flag
    public function updatePassword(int $id, string $newHash): bool
    {
        $stmt = $this->db->prepare("
            UPDATE users
            SET password_hash = ?, first_login = 0
            WHERE id = ?
        ");
        return $stmt->execute([$newHash, $id]);
    }
}

