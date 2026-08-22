<?php

declare(strict_types=1);

final class AuthRepository
{
    private const TOKEN_LENGTH = 64;
    private const SESSION_HOURS = 24;

    public function __construct(private PDO $db)
    {
    }

    public function login(string $identifier, string $password): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, username, full_name, role, status, password_hash
             FROM tsca_users
             WHERE (username = :id OR lower(username) = lower(:id))
               AND status = \'ACTIVE\''
        );
        $stmt->execute([':id' => $identifier]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return null;
        }

        // Update last_login_at
        $upd = $this->db->prepare('UPDATE tsca_users SET last_login_at = NOW() WHERE id = :id');
        $upd->execute([':id' => $user['id']]);

        // Create session token
        $token = $this->generateToken();
        $expires = date('Y-m-d H:i:s', time() + self::SESSION_HOURS * 3600);
        $ins = $this->db->prepare(
            'INSERT INTO user_sessions (user_id, token, ip_address, user_agent, expires_at)
             VALUES (:uid, :token, :ip, :ua, :expires)'
        );
        $ins->execute([
            ':uid' => $user['id'],
            ':token' => $token,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ':expires' => $expires,
        ]);

        return [
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'full_name' => $user['full_name'],
                'role' => $user['role'],
            ],
        ];
    }

    public function validateToken(string $token): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT s.id AS session_id, u.id, u.username, u.full_name, u.role, u.status
             FROM user_sessions s
             JOIN tsca_users u ON s.user_id = u.id
             WHERE s.token = :token AND s.expires_at > NOW() AND u.status = \'ACTIVE\''
        );
        $stmt->execute([':token' => $token]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function logout(string $token): bool
    {
        $stmt = $this->db->prepare('DELETE FROM user_sessions WHERE token = :token');
        $stmt->execute([':token' => $token]);
        return $stmt->rowCount() > 0;
    }

    public function logoutAll(int $userId): int
    {
        $stmt = $this->db->prepare('DELETE FROM user_sessions WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);
        return $stmt->rowCount();
    }

    public function purgeExpired(): int
    {
        $stmt = $this->db->prepare('DELETE FROM user_sessions WHERE expires_at < NOW()');
        $stmt->execute();
        return $stmt->rowCount();
    }

    public function bootstrapAdmin(string $secret, string $username, string $email, string $password, string $fullName): ?array
    {
        $expectedSecret = Config::get('REGISTRY_ADMIN_KEY');
        if (!$expectedSecret || !hash_equals($expectedSecret, $secret)) {
            return null;
        }

        // Check if an ADMINISTRATOR already exists
        $check = $this->db->query("SELECT COUNT(*) FROM tsca_users WHERE role = 'ADMINISTRATOR'");
        if ((int)$check->fetchColumn() > 0) {
            return ['error' => 'bootstrap_completed'];
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $this->db->prepare(
            'INSERT INTO tsca_users (username, password_hash, full_name, role, status)
             VALUES (:username, :hash, :name, \'ADMINISTRATOR\', \'ACTIVE\')
             RETURNING id, username, full_name, role'
        );
        $stmt->execute([
            ':username' => $username,
            ':hash' => $hash,
            ':name' => $fullName,
        ]);
        return $stmt->fetch();
    }

    public function bootstrapComplete(): bool
    {
        $check = $this->db->query("SELECT COUNT(*) FROM tsca_users WHERE role = 'ADMINISTRATOR'");
        return (int)$check->fetchColumn() > 0;
    }

    public function findUser(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, username, full_name, role, status, created_at, updated_at, last_login_at
             FROM tsca_users WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findUserByUsername(string $username): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, username, full_name, role, status, created_at, updated_at, last_login_at
             FROM tsca_users WHERE lower(username) = lower(:username)'
        );
        $stmt->execute([':username' => $username]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function createUser(array $data): array
    {
        $hash = password_hash($data['password'], PASSWORD_BCRYPT);
        $stmt = $this->db->prepare(
            'INSERT INTO tsca_users (username, password_hash, full_name, role, status)
             VALUES (:username, :hash, :name, :role, :status)
             RETURNING id, username, full_name, role, status, created_at'
        );
        $stmt->execute([
            ':username' => $data['username'],
            ':hash' => $hash,
            ':name' => $data['full_name'],
            ':role' => $data['role'],
            ':status' => $data['status'] ?? 'ACTIVE',
        ]);
        return $stmt->fetch();
    }

    public function updateUser(int $id, array $data): ?array
    {
        $fields = [];
        $params = [':id' => $id];
        foreach (['full_name', 'role', 'status'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }
        if (isset($data['password']) && $data['password'] !== '') {
            $fields[] = 'password_hash = :password_hash';
            $params[':password_hash'] = password_hash($data['password'], PASSWORD_BCRYPT);
        }
        if (!$fields) return $this->findUser($id);
        $fields[] = 'updated_at = NOW()';
        $stmt = $this->db->prepare('UPDATE tsca_users SET ' . implode(', ', $fields) . ' WHERE id = :id RETURNING id, username, full_name, role, status, created_at, updated_at');
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function disableUser(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE tsca_users SET status = 'DISABLED', updated_at = NOW() WHERE id = :id AND role != 'ADMINISTRATOR'");
        $stmt->execute([':id' => $id]);
        if ($stmt->rowCount() > 0) {
            $this->logoutAll($id);
            return true;
        }
        return false;
    }

    public function enableUser(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE tsca_users SET status = 'ACTIVE', updated_at = NOW() WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function listUsers(): array
    {
        $stmt = $this->db->query(
            'SELECT id, username, full_name, role, status, created_at, updated_at, last_login_at
             FROM tsca_users ORDER BY created_at DESC'
        );
        return $stmt->fetchAll();
    }

    public function deleteUser(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM tsca_users WHERE id = :id AND role != 'ADMINISTRATOR'");
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    private function generateToken(): string
    {
        return bin2hex(random_bytes(self::TOKEN_LENGTH));
    }
}
