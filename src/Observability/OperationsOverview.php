<?php

namespace App\Observability;

use Doctrine\DBAL\Connection;

/** Project-wide totals and bounded lists; independent of the board's current page. */
final readonly class OperationsOverview
{
    public const GROUPS = [
        'ready' => ['backlog', 'ready'],
        'progress' => ['claimed', 'running', 'completion_pending_evidence'],
        'attention' => ['blocked', 'awaiting_review', 'failed'],
        'done' => ['completed', 'cancelled'],
    ];

    public function __construct(private Connection $db)
    {
    }

    public function read(?string $project): array
    {
        $counts = array_fill_keys(DashboardProjection::STATES, 0);
        foreach ($this->db->fetchAllAssociative('SELECT status, COUNT(*) AS total FROM task WHERE project_id = ? GROUP BY status', [$project]) as $row) {
            $counts[$row['status']] = (int) $row['total'];
        }
        $from = ' FROM task t LEFT JOIN task_lease l ON l.task_id = t.id LEFT JOIN agent_session s ON s.id = l.session_id LEFT JOIN agent a ON a.id = s.agent_id ';
        $attention = "(t.status IN ('blocked', 'awaiting_review', 'failed') OR (t.status IN ('claimed', 'running') AND l.expires_at <= CURRENT_TIMESTAMP AT TIME ZONE 'UTC') OR EXISTS (SELECT 1 FROM completion_receipt r WHERE r.task_id = t.id AND r.status = 'inconsistent'))";
        $columns = 'SELECT t.id, t.title, t.status, t.version, a.name AS agent, l.expires_at AS lease_expires_at';
        $activeAgents = (int) $this->db->fetchOne("SELECT COUNT(DISTINCT a.id) $from WHERE t.project_id = ? AND l.expires_at > CURRENT_TIMESTAMP AT TIME ZONE 'UTC' AND s.last_heartbeat_at > (CURRENT_TIMESTAMP AT TIME ZONE 'UTC') - INTERVAL '5 minutes' AND t.status IN ('claimed', 'running', 'blocked', 'awaiting_review', 'completion_pending_evidence')", [$project]);
        $groups = [];
        foreach (self::GROUPS as $group => $states) {
            $groups[$group] = array_sum(array_intersect_key($counts, array_flip($states)));
        }

        return [
            'counts' => $counts, 'group_counts' => $groups, 'active_agents' => $activeAgents,
            'open_tasks' => array_sum($counts) - $counts['completed'] - $counts['cancelled'],
            'attention_count' => (int) $this->db->fetchOne("SELECT COUNT(*) $from WHERE t.project_id = ? AND $attention", [$project]),
            'attention' => $this->db->fetchAllAssociative("$columns $from WHERE t.project_id = ? AND $attention ORDER BY CASE t.status WHEN 'failed' THEN 0 WHEN 'blocked' THEN 1 ELSE 2 END, t.updated_at DESC, t.id LIMIT 6", [$project]),
            'running' => $this->db->fetchAllAssociative("$columns $from WHERE t.project_id = ? AND t.status IN ('claimed', 'running', 'completion_pending_evidence') ORDER BY t.updated_at DESC, t.id LIMIT 6", [$project]),
            'activity' => $this->db->fetchAllAssociative('SELECT e.id, e.task_id, t.title, e.to_state, e.actor_id, e.created_at FROM state_event e JOIN task t ON t.id = e.task_id WHERE t.project_id = ? ORDER BY e.created_at DESC, e.id DESC LIMIT 8', [$project]),
        ];
    }
}
