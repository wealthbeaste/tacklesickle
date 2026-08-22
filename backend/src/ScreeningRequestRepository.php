<?php

declare(strict_types=1);

final class ScreeningRequestRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function create(array $data): array
    {
        $stmt = $this->db->prepare(
            'INSERT INTO screening_requests (full_name, phone, email, gender, district, preferred_date, preferred_location, notes)
             VALUES (:full_name, :phone, :email, :gender, :district, :preferred_date, :preferred_location, :notes)
             RETURNING *'
        );
        $stmt->execute([
            ':full_name' => trim($data['full_name']),
            ':phone' => !empty($data['phone']) ? trim($data['phone']) : null,
            ':email' => !empty($data['email']) ? strtolower(trim($data['email'])) : null,
            ':gender' => !empty($data['gender']) ? $data['gender'] : null,
            ':district' => !empty($data['district']) ? trim($data['district']) : null,
            ':preferred_date' => !empty($data['preferred_date']) ? $data['preferred_date'] : null,
            ':preferred_location' => !empty($data['preferred_location']) ? trim($data['preferred_location']) : null,
            ':notes' => !empty($data['notes']) ? trim($data['notes']) : null,
        ]);
        return $stmt->fetch();
    }

    public function list(array $filters): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['search'])) {
            $where[] = '(full_name ILIKE :search OR COALESCE(phone, \'\') ILIKE :search OR COALESCE(email, \'\') ILIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params[':status'] = $filters['status'];
        }

        $limit = max(1, min(100, (int)($filters['limit'] ?? 25)));
        $page = max(1, (int)($filters['page'] ?? 1));
        $offset = ($page - 1) * $limit;
        $clause = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $count = $this->db->prepare('SELECT COUNT(*) FROM screening_requests' . $clause);
        $count->execute($params);
        $total = (int)$count->fetchColumn();

        $stmt = $this->db->prepare(
            'SELECT * FROM screening_requests' . $clause . ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset'
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
        $stmt = $this->db->prepare('SELECT * FROM screening_requests WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function updateStatus(int $id, string $status): ?array
    {
        $stmt = $this->db->prepare(
            'UPDATE screening_requests SET status = :status, updated_at = NOW() WHERE id = :id RETURNING *'
        );
        $stmt->execute([':status' => $status, ':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function confirmAndCreateParticipant(int $id, int $createdBy): ?array
    {
        $this->db->beginTransaction();
        try {
            $request = $this->find($id);
            if (!$request) {
                $this->db->rollBack();
                return null;
            }

            $nameParts = explode(' ', $request['full_name'], 2);
            $firstName = $nameParts[0];
            $lastName = $nameParts[1] ?? '';

            $next = (int)$this->db->query("SELECT nextval(pg_get_serial_sequence('participants', 'id'))")->fetchColumn();
            $tscaId = 'TSCA-' . date('Y') . '-' . str_pad((string)$next, 6, '0', STR_PAD_LEFT);

            $stmt = $this->db->prepare(
                'INSERT INTO participants (id, tsca_id, first_name, last_name, gender, phone, district, notes, created_by)
                 VALUES (:id, :tsca_id, :first_name, :last_name, :gender, :phone, :district, :notes, :created_by)
                 RETURNING id'
            );
            $stmt->execute([
                ':id' => $next,
                ':tsca_id' => $tscaId,
                ':first_name' => $firstName,
                ':last_name' => $lastName,
                ':gender' => $request['gender'] ?? 'other',
                ':phone' => $request['phone'],
                ':district' => $request['district'],
                ':notes' => 'Auto-created from screening request #' . $id . ($request['preferred_date'] ? ' | Preferred date: ' . $request['preferred_date'] : '') . ($request['preferred_location'] ? ' | Preferred location: ' . $request['preferred_location'] : ''),
                ':created_by' => $createdBy,
            ]);
            $participant = $stmt->fetch();

            $upd = $this->db->prepare(
                'UPDATE screening_requests SET status = \'confirmed\', participant_id = :pid, updated_at = NOW() WHERE id = :id'
            );
            $upd->execute([':pid' => $participant['id'], ':id' => $id]);

            $this->db->commit();

            return $this->find($id);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM screening_requests WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function stats(): array
    {
        $row = $this->db->query(
            "SELECT
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE status = 'pending') AS pending,
                COUNT(*) FILTER (WHERE status = 'confirmed') AS confirmed,
                COUNT(*) FILTER (WHERE status = 'completed') AS completed,
                COUNT(*) FILTER (WHERE status = 'cancelled') AS cancelled
             FROM screening_requests"
        )->fetch();
        return array_map('intval', $row ?: []);
    }
}
