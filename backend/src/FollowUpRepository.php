<?php

declare(strict_types=1);

final class FollowUpRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function create(array $data): array
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO follow_ups (participant_id, screening_id, follow_up_date, referral_needed, referral_facility, referral_reason, follow_up_status, follow_up_outcome, counseling_notes, next_follow_up_date, created_by)
                 VALUES (:participant_id, :screening_id, :follow_up_date, :referral_needed, :referral_facility, :referral_reason, :follow_up_status, :follow_up_outcome, :counseling_notes, :next_follow_up_date, :created_by)
                 RETURNING *'
            );
            $stmt->execute([
                ':participant_id' => (int)$data['participant_id'],
                ':screening_id' => !empty($data['screening_id']) ? (int)$data['screening_id'] : null,
                ':follow_up_date' => $data['follow_up_date'],
                ':referral_needed' => !empty($data['referral_needed']),
                ':referral_facility' => !empty($data['referral_facility']) ? trim($data['referral_facility']) : null,
                ':referral_reason' => !empty($data['referral_reason']) ? trim($data['referral_reason']) : null,
                ':follow_up_status' => $data['follow_up_status'] ?? 'pending',
                ':follow_up_outcome' => !empty($data['follow_up_outcome']) ? trim($data['follow_up_outcome']) : null,
                ':counseling_notes' => !empty($data['counseling_notes']) ? trim($data['counseling_notes']) : null,
                ':next_follow_up_date' => !empty($data['next_follow_up_date']) ? $data['next_follow_up_date'] : null,
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
            'SELECT f.*, s.screening_date, s.result
             FROM follow_ups f
             LEFT JOIN screenings s ON f.screening_id = s.id
             WHERE f.participant_id = :participant_id
             ORDER BY f.follow_up_date DESC'
        );
        $stmt->execute([':participant_id' => $participantId]);
        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT f.*, s.screening_date, s.result, p.tsca_id, p.first_name, p.last_name
             FROM follow_ups f
             LEFT JOIN screenings s ON f.screening_id = s.id
             LEFT JOIN participants p ON f.participant_id = p.id
             WHERE f.id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function update(int $id, array $data): ?array
    {
        $fields = [];
        $params = [':id' => $id];
        foreach (['follow_up_date', 'referral_needed', 'referral_facility', 'referral_reason', 'follow_up_status', 'follow_up_outcome', 'counseling_notes', 'next_follow_up_date'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $data[$field] !== '' && $data[$field] !== null ? $data[$field] : null;
            }
        }
        if (!$fields) return $this->find($id);
        $fields[] = 'updated_at = NOW()';
        $stmt = $this->db->prepare('UPDATE follow_ups SET ' . implode(', ', $fields) . ' WHERE id = :id RETURNING *');
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM follow_ups WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function stats(): array
    {
        $row = $this->db->query(
            "SELECT
                COUNT(*) AS total_follow_ups,
                COUNT(*) FILTER (WHERE follow_up_status = 'pending') AS pending,
                COUNT(*) FILTER (WHERE follow_up_status = 'completed') AS completed,
                COUNT(*) FILTER (WHERE follow_up_status = 'cancelled') AS cancelled,
                COUNT(*) FILTER (WHERE follow_up_status = 'lost_to_follow_up') AS lost,
                COUNT(*) FILTER (WHERE referral_needed = true) AS referrals_needed,
                COUNT(*) FILTER (WHERE referral_needed = true AND follow_up_status = 'completed') AS referrals_completed
             FROM follow_ups"
        )->fetch();
        $result = [];
        foreach ($row as $k => $v) {
            $result[$k] = (int)$v;
        }
        return $result;
    }
}
