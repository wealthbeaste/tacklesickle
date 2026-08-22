<?php

declare(strict_types=1);

final class ReportsRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function summary(): array
    {
        $participants = $this->db->query(
            "SELECT
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE created_at >= CURRENT_DATE) AS today,
                COUNT(*) FILTER (WHERE created_at >= CURRENT_DATE - INTERVAL '7 days') AS this_week,
                COUNT(*) FILTER (WHERE created_at >= CURRENT_DATE - INTERVAL '30 days') AS this_month
             FROM participants"
        )->fetch();

        $screenings = $this->db->query(
            "SELECT
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE screening_date = CURRENT_DATE) AS today,
                COUNT(*) FILTER (WHERE screening_date >= CURRENT_DATE - INTERVAL '7 days') AS this_week,
                COUNT(*) FILTER (WHERE screening_date >= CURRENT_DATE - INTERVAL '30 days') AS this_month
             FROM screenings"
        )->fetch();

        $followUps = $this->db->query(
            "SELECT
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE follow_up_status = 'pending') AS pending,
                COUNT(*) FILTER (WHERE referral_needed = true) AS referrals
             FROM follow_ups"
        )->fetch();

        $events = $this->db->query(
            "SELECT COUNT(*) AS total FROM outreach_events"
        )->fetch();

        return [
            'participants' => array_map('intval', $participants),
            'screenings' => array_map('intval', $screenings),
            'follow_ups' => array_map('intval', $followUps),
            'events' => ['total' => (int)$events['total']],
        ];
    }

    public function resultDistribution(): array
    {
        $stmt = $this->db->query(
            "SELECT result, COUNT(*) AS count
             FROM screenings
             GROUP BY result
             ORDER BY count DESC"
        );
        return $stmt->fetchAll();
    }

    public function demographics(): array
    {
        $gender = $this->db->query(
            "SELECT gender, COUNT(*) AS count FROM participants GROUP BY gender ORDER BY count DESC"
        )->fetchAll();

        $ageGroups = $this->db->query(
            "SELECT
                CASE
                    WHEN age < 5 THEN '0-4'
                    WHEN age < 10 THEN '5-9'
                    WHEN age < 15 THEN '10-14'
                    WHEN age < 18 THEN '15-17'
                    WHEN age < 30 THEN '18-29'
                    WHEN age < 45 THEN '30-44'
                    WHEN age < 60 THEN '45-59'
                    ELSE '60+'
                END AS age_group,
                COUNT(*) AS count
             FROM participants
             WHERE age IS NOT NULL
             GROUP BY age_group
             ORDER BY MIN(age)"
        )->fetchAll();

        $districts = $this->db->query(
            "SELECT district, COUNT(*) AS count FROM participants WHERE district IS NOT NULL GROUP BY district ORDER BY count DESC"
        )->fetchAll();

        return [
            'gender' => $gender,
            'age_groups' => $ageGroups,
            'districts' => $districts,
        ];
    }

    public function eventReport(?int $eventId = null): array
    {
        if ($eventId) {
            $event = (new EventRepository($this->db))->find($eventId);
            if (!$event) return [];

            $screeningCount = $this->db->prepare('SELECT COUNT(*) FROM screenings WHERE event_id = :event_id');
            $screeningCount->execute([':event_id' => $eventId]);

            $results = $this->db->prepare(
                "SELECT result, COUNT(*) AS count FROM screenings WHERE event_id = :event_id GROUP BY result"
            );
            $results->execute([':event_id' => $eventId]);

            return [
                'event' => $event,
                'total_screenings' => (int)$screeningCount->fetchColumn(),
                'result_distribution' => $results->fetchAll(),
            ];
        }

        $events = $this->db->query(
            "SELECT oe.*, COUNT(s.id) AS screening_count
             FROM outreach_events oe
             LEFT JOIN screenings s ON oe.id = s.event_id
             GROUP BY oe.id
             ORDER BY oe.event_date DESC"
        )->fetchAll();

        return ['events' => $events];
    }

    public function referralReport(): array
    {
        $byStatus = $this->db->query(
            "SELECT follow_up_status, COUNT(*) AS count
             FROM follow_ups
             GROUP BY follow_up_status
             ORDER BY count DESC"
        )->fetchAll();

        $referrals = $this->db->query(
            "SELECT f.*, p.tsca_id, p.first_name, p.last_name
             FROM follow_ups f
             JOIN participants p ON f.participant_id = p.id
             WHERE f.referral_needed = true
             ORDER BY f.follow_up_date DESC"
        )->fetchAll();

        return [
            'by_status' => $byStatus,
            'referrals' => $referrals,
        ];
    }

    public function exportParticipants(array $filters = []): array
    {
        $repo = new ParticipantRepository($this->db);
        return $repo->list(array_merge($filters, ['limit' => 10000]))['items'];
    }

    public function exportScreenings(array $filters = []): array
    {
        $repo = new ScreeningRepository($this->db);
        return $repo->list(array_merge($filters, ['limit' => 10000]))['items'];
    }
}
