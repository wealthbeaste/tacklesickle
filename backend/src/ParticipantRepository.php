<?php

declare(strict_types=1);

final class ParticipantRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function create(array $data): array
    {
        $this->db->beginTransaction();
        try {
            // Generate TSCA ID
            $next = (int)$this->db->query("SELECT nextval(pg_get_serial_sequence('participants', 'id'))")->fetchColumn();
            $tscaId = 'TSCA-' . date('Y') . '-' . str_pad((string)$next, 6, '0', STR_PAD_LEFT);

            $age = isset($data['age']) && $data['age'] !== '' ? (int)$data['age'] : null;
            $isMinor = $age !== null && $age < 18;

            $stmt = $this->db->prepare(
                'INSERT INTO participants (id, tsca_id, first_name, last_name, age, date_of_birth, gender, phone, national_id, is_minor, guardian_name, guardian_phone, guardian_relationship, district, sub_county, village, notes, created_by)
                 VALUES (:id, :tsca_id, :first_name, :last_name, :age, :date_of_birth, :gender, :phone, :national_id, :is_minor, :guardian_name, :guardian_phone, :guardian_relationship, :district, :sub_county, :village, :notes, :created_by)
                 RETURNING *'
            );
            $stmt->execute([
                ':id' => $next,
                ':tsca_id' => $tscaId,
                ':first_name' => trim($data['first_name']),
                ':last_name' => trim($data['last_name']),
                ':age' => $age,
                ':date_of_birth' => !empty($data['date_of_birth']) ? $data['date_of_birth'] : null,
                ':gender' => $data['gender'],
                ':phone' => !empty($data['phone']) ? trim($data['phone']) : null,
                ':national_id' => !empty($data['national_id']) ? trim($data['national_id']) : null,
                ':is_minor' => $isMinor ? 't' : 'f',
                ':guardian_name' => !empty($data['guardian_name']) ? trim($data['guardian_name']) : null,
                ':guardian_phone' => !empty($data['guardian_phone']) ? trim($data['guardian_phone']) : null,
                ':guardian_relationship' => !empty($data['guardian_relationship']) ? trim($data['guardian_relationship']) : null,
                ':district' => !empty($data['district']) ? trim($data['district']) : null,
                ':sub_county' => !empty($data['sub_county']) ? trim($data['sub_county']) : null,
                ':village' => !empty($data['village']) ? trim($data['village']) : null,
                ':notes' => !empty($data['notes']) ? trim($data['notes']) : null,
                ':created_by' => $data['created_by'] ?? null,
            ]);
            $row = $stmt->fetch();
            $this->db->commit();
            return $row;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM participants WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByTscaId(string $tscaId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM participants WHERE tsca_id = :tsca_id');
        $stmt->execute([':tsca_id' => $tscaId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function update(int $id, array $data): ?array
    {
        $fields = [];
        $params = [':id' => $id];
        foreach (['first_name', 'last_name', 'age', 'date_of_birth', 'gender', 'phone', 'national_id', 'guardian_name', 'guardian_phone', 'guardian_relationship', 'district', 'sub_county', 'village', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $data[$field] !== '' && $data[$field] !== null ? $data[$field] : null;
            }
        }
        if (!$fields) return $this->find($id);
        $fields[] = 'updated_at = NOW()';
        $stmt = $this->db->prepare('UPDATE participants SET ' . implode(', ', $fields) . ' WHERE id = :id RETURNING *');
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM participants WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function list(array $filters): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['search'])) {
            $where[] = '(tsca_id ILIKE :search OR first_name ILIKE :search OR last_name ILIKE :search OR phone ILIKE :search OR national_id ILIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['district'])) {
            $where[] = 'district = :district';
            $params[':district'] = $filters['district'];
        }
        if (!empty($filters['gender'])) {
            $where[] = 'gender = :gender';
            $params[':gender'] = $filters['gender'];
        }
        if (!empty($filters['is_minor'])) {
            $where[] = 'is_minor = :is_minor';
            $params[':is_minor'] = $filters['is_minor'] === 'true';
        }

        $limit = max(1, min(100, (int)($filters['limit'] ?? 25)));
        $page = max(1, (int)($filters['page'] ?? 1));
        $offset = ($page - 1) * $limit;
        $clause = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $count = $this->db->prepare('SELECT COUNT(*) FROM participants' . $clause);
        $count->execute($params);
        $total = (int)$count->fetchColumn();

        $stmt = $this->db->prepare(
            'SELECT * FROM participants' . $clause . ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset'
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

    public function stats(): array
    {
        $row = $this->db->query(
            "SELECT
                COUNT(*) AS total_participants,
                COUNT(*) FILTER (WHERE gender = 'male') AS male,
                COUNT(*) FILTER (WHERE gender = 'female') AS female,
                COUNT(*) FILTER (WHERE is_minor = true) AS minors,
                COUNT(*) FILTER (WHERE created_at >= CURRENT_DATE) AS registered_today
             FROM participants"
        )->fetch();
        return array_map('intval', $row ?: []);
    }
}
