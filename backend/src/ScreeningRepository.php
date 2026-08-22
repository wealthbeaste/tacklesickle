<?php

declare(strict_types=1);

final class ScreeningRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function create(array $data): array
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO screenings (participant_id, event_id, screening_date, screening_site, test_type, result, health_worker_name, health_worker_id, counselor_notes, created_by)
                 VALUES (:participant_id, :event_id, :screening_date, :screening_site, :test_type, :result, :health_worker_name, :health_worker_id, :counselor_notes, :created_by)
                 RETURNING *'
            );
            $stmt->execute([
                ':participant_id' => (int)$data['participant_id'],
                ':event_id' => !empty($data['event_id']) ? (int)$data['event_id'] : null,
                ':screening_date' => $data['screening_date'],
                ':screening_site' => !empty($data['screening_site']) ? trim($data['screening_site']) : null,
                ':test_type' => $data['test_type'],
                ':result' => $data['result'],
                ':health_worker_name' => !empty($data['health_worker_name']) ? trim($data['health_worker_name']) : null,
                ':health_worker_id' => !empty($data['health_worker_id']) ? trim($data['health_worker_id']) : null,
                ':counselor_notes' => !empty($data['counselor_notes']) ? trim($data['counselor_notes']) : null,
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

    public function findByParticipant(int $participantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT s.*, oe.event_name, oe.district AS event_district
             FROM screenings s
             LEFT JOIN outreach_events oe ON s.event_id = oe.id
             WHERE s.participant_id = :participant_id
             ORDER BY s.screening_date DESC'
        );
        $stmt->execute([':participant_id' => $participantId]);
        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT s.*, oe.event_name, oe.district AS event_district
             FROM screenings s
             LEFT JOIN outreach_events oe ON s.event_id = oe.id
             WHERE s.id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function update(int $id, array $data): ?array
    {
        $fields = [];
        $params = [':id' => $id];
        foreach (['event_id', 'screening_date', 'screening_site', 'test_type', 'result', 'health_worker_name', 'health_worker_id', 'counselor_notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $data[$field] !== '' && $data[$field] !== null ? $data[$field] : null;
            }
        }
        if (!$fields) return $this->find($id);
        $fields[] = 'updated_at = NOW()';
        $stmt = $this->db->prepare('UPDATE screenings SET ' . implode(', ', $fields) . ' WHERE id = :id RETURNING *');
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM screenings WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function list(array $filters): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['participant_id'])) {
            $where[] = 's.participant_id = :participant_id';
            $params[':participant_id'] = (int)$filters['participant_id'];
        }
        if (!empty($filters['event_id'])) {
            $where[] = 's.event_id = :event_id';
            $params[':event_id'] = (int)$filters['event_id'];
        }
        if (!empty($filters['result'])) {
            $where[] = 's.result = :result';
            $params[':result'] = $filters['result'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 's.screening_date >= :date_from';
            $params[':date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 's.screening_date <= :date_to';
            $params[':date_to'] = $filters['date_to'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(p.tsca_id ILIKE :search OR p.first_name ILIKE :search OR p.last_name ILIKE :search OR s.screening_site ILIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $limit = max(1, min(100, (int)($filters['limit'] ?? 25)));
        $page = max(1, (int)($filters['page'] ?? 1));
        $offset = ($page - 1) * $limit;
        $clause = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $joinClause = ' FROM screenings s LEFT JOIN participants p ON s.participant_id = p.id LEFT JOIN outreach_events oe ON s.event_id = oe.id';

        $count = $this->db->prepare('SELECT COUNT(*)' . $joinClause . $clause);
        $count->execute($params);
        $total = (int)$count->fetchColumn();

        $stmt = $this->db->prepare(
            'SELECT s.*, p.tsca_id, p.first_name, p.last_name, oe.event_name' . $joinClause . $clause . ' ORDER BY s.screening_date DESC LIMIT :limit OFFSET :offset'
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
                COUNT(*) AS total_screenings,
                COUNT(*) FILTER (WHERE result = 'reactive') AS reactive,
                COUNT(*) FILTER (WHERE result = 'non_reactive') AS non_reactive,
                COUNT(*) FILTER (WHERE result = 'AA') AS aa,
                COUNT(*) FILTER (WHERE result = 'AS') AS \"as\",
                COUNT(*) FILTER (WHERE result = 'SS') AS ss,
                COUNT(*) FILTER (WHERE result = 'SC') AS sc,
                COUNT(*) FILTER (WHERE result = 'unknown') AS unknown_type,
                COUNT(*) FILTER (WHERE screening_date = CURRENT_DATE) AS screened_today
             FROM screenings"
        )->fetch();
        $result = [];
        foreach ($row as $k => $v) {
            $result[$k] = (int)$v;
        }
        return $result;
    }
}
