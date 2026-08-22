<?php

declare(strict_types=1);

final class EventRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function create(array $data): array
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO outreach_events (event_name, district, location, event_date, team_lead, partners, description, created_by)
                 VALUES (:event_name, :district, :location, :event_date, :team_lead, :partners, :description, :created_by)
                 RETURNING *'
            );
            $stmt->execute([
                ':event_name' => trim($data['event_name']),
                ':district' => trim($data['district']),
                ':location' => !empty($data['location']) ? trim($data['location']) : null,
                ':event_date' => $data['event_date'],
                ':team_lead' => !empty($data['team_lead']) ? trim($data['team_lead']) : null,
                ':partners' => !empty($data['partners']) ? trim($data['partners']) : null,
                ':description' => !empty($data['description']) ? trim($data['description']) : null,
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
        $stmt = $this->db->prepare('SELECT * FROM outreach_events WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function update(int $id, array $data): ?array
    {
        $fields = [];
        $params = [':id' => $id];
        foreach (['event_name', 'district', 'location', 'event_date', 'team_lead', 'partners', 'description'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $data[$field] !== '' && $data[$field] !== null ? $data[$field] : null;
            }
        }
        if (!$fields) return $this->find($id);
        $fields[] = 'updated_at = NOW()';
        $stmt = $this->db->prepare('UPDATE outreach_events SET ' . implode(', ', $fields) . ' WHERE id = :id RETURNING *');
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM outreach_events WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function list(array $filters): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['search'])) {
            $where[] = '(event_name ILIKE :search OR district ILIKE :search OR location ILIKE :search OR team_lead ILIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['district'])) {
            $where[] = 'district = :district';
            $params[':district'] = $filters['district'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'event_date >= :date_from';
            $params[':date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'event_date <= :date_to';
            $params[':date_to'] = $filters['date_to'];
        }

        $limit = max(1, min(100, (int)($filters['limit'] ?? 25)));
        $page = max(1, (int)($filters['page'] ?? 1));
        $offset = ($page - 1) * $limit;
        $clause = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $count = $this->db->prepare('SELECT COUNT(*) FROM outreach_events' . $clause);
        $count->execute($params);
        $total = (int)$count->fetchColumn();

        $stmt = $this->db->prepare(
            'SELECT * FROM outreach_events' . $clause . ' ORDER BY event_date DESC LIMIT :limit OFFSET :offset'
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
}
