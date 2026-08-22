<?php

declare(strict_types=1);

final class RegistryRepository
{
    public function __construct(private PDO $db)
    {
        $this->ensureSchema();
    }

    private function ensureSchema(): void
    {
        $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS registry_entries (
    id BIGSERIAL PRIMARY KEY,
    registry_number VARCHAR(32) NOT NULL UNIQUE,
    full_name VARCHAR(160) NOT NULL,
    email VARCHAR(254) NOT NULL UNIQUE,
    phone VARCHAR(40),
    subscription_type VARCHAR(20) NOT NULL CHECK (subscription_type IN ('newsletter', 'volunteer', 'member')),
    status VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'inactive')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_registry_entries_subscription_type ON registry_entries(subscription_type);
CREATE INDEX IF NOT EXISTS idx_registry_entries_status ON registry_entries(status);
CREATE INDEX IF NOT EXISTS idx_registry_entries_created_at ON registry_entries(created_at DESC);
SQL;
        $this->db->exec($sql);
    }

    public function create(array $data): array
    {
        $this->db->beginTransaction();
        try {
            $next = (int)$this->db->query("SELECT nextval(pg_get_serial_sequence('registry_entries', 'id'))")->fetchColumn();
            $registryNumber = 'TSCA-' . date('Y') . '-' . str_pad((string)$next, 6, '0', STR_PAD_LEFT);

            $stmt = $this->db->prepare(
                'INSERT INTO registry_entries (id, registry_number, full_name, email, phone, subscription_type)
                 VALUES (:id, :registry_number, :full_name, :email, :phone, :subscription_type)
                 RETURNING *'
            );
            $stmt->execute([
                ':id' => $next,
                ':registry_number' => $registryNumber,
                ':full_name' => $data['full_name'],
                ':email' => $data['email'],
                ':phone' => $data['phone'] !== '' ? $data['phone'] : null,
                ':subscription_type' => $data['subscription_type'],
            ]);
            $row = $stmt->fetch();
            $this->db->commit();
            return $row;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function list(array $filters): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['search'])) {
            $where[] = '(full_name ILIKE :search OR email ILIKE :search OR registry_number ILIKE :search OR COALESCE(phone, \'\') ILIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['subscription_type'])) {
            $where[] = 'subscription_type = :subscription_type';
            $params[':subscription_type'] = $filters['subscription_type'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params[':status'] = $filters['status'];
        }

        $limit = max(1, min(100, (int)($filters['limit'] ?? 25)));
        $page = max(1, (int)($filters['page'] ?? 1));
        $offset = ($page - 1) * $limit;
        $clause = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $count = $this->db->prepare('SELECT COUNT(*) FROM registry_entries' . $clause);
        $count->execute($params);
        $total = (int)$count->fetchColumn();

        $stmt = $this->db->prepare(
            'SELECT * FROM registry_entries' . $clause . ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => $stmt->fetchAll(),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int)ceil($total / $limit),
            ],
        ];
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM registry_entries WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function update(int $id, array $data): ?array
    {
        $fields = [];
        $params = [':id' => $id];
        foreach (['full_name', 'email', 'phone', 'subscription_type', 'status'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $field === 'phone' && $data[$field] === '' ? null : $data[$field];
            }
        }
        if (!$fields) {
            return $this->find($id);
        }
        $fields[] = 'updated_at = NOW()';
        $stmt = $this->db->prepare('UPDATE registry_entries SET ' . implode(', ', $fields) . ' WHERE id = :id RETURNING *');
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM registry_entries WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function stats(): array
    {
        $row = $this->db->query(
            "SELECT
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE status = 'active') AS active,
                COUNT(*) FILTER (WHERE status = 'inactive') AS inactive,
                COUNT(*) FILTER (WHERE subscription_type = 'newsletter') AS newsletter,
                COUNT(*) FILTER (WHERE subscription_type = 'volunteer') AS volunteer,
                COUNT(*) FILTER (WHERE subscription_type = 'member') AS member
             FROM registry_entries"
        )->fetch();
        return array_map('intval', $row ?: []);
    }
}
