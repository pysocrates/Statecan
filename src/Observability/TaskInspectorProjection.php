<?php

namespace App\Observability;

use App\Dto\Observability\TaskInspectorQuery;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Focused authoritative reads. No evidence storage, Git process or mutation is invoked. */
final readonly class TaskInspectorProjection
{
    public const PAGE_SIZE = 25;

    public function __construct(private Connection $db) {}

    public function read(string $id, TaskInspectorQuery $query): array
    {
        return $this->db->transactional(function () use ($id, $query): array {
            $this->db->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $task = $this->db->fetchAssociative('SELECT t.id, t.project_id, p.name AS project_name, t.title, t.status, t.version, t.contract_version, t.created_at, t.updated_at FROM task t JOIN project p ON p.id = t.project_id WHERE t.id = ?', [$id]);
            if (!$task || (null !== $query->project && $query->project !== $task['project_id'])) {
                throw new NotFoundHttpException('Task not found in selected project.');
            }
            $version = $query->contractVersion ?? $task['contract_version'];
            $contract = $this->db->fetchAssociative('SELECT version, document, created_at FROM task_contract WHERE task_id = ? AND version = ?', [$id, $version]);
            if (!$contract) { throw new NotFoundHttpException('Contract version not found.'); }
            $contract['document'] = json_decode($contract['document'], true, flags: JSON_THROW_ON_ERROR);
            $lease = $this->db->fetchAssociative("SELECT l.session_id, l.generation, l.expires_at, l.expires_at > CURRENT_TIMESTAMP AT TIME ZONE 'UTC' AS active, s.created_at AS session_created_at, s.last_heartbeat_at, a.id AS agent_id, a.name AS agent_name, s.last_heartbeat_at > CURRENT_TIMESTAMP AT TIME ZONE 'UTC' - INTERVAL '5 minutes' AS session_fresh FROM task_lease l JOIN agent_session s ON s.id = l.session_id JOIN agent a ON a.id = s.agent_id WHERE l.task_id = ?", [$id]);
            $offset = $query->page * self::PAGE_SIZE;
            $suffix = ' LIMIT 26 OFFSET '.$offset;
            $sections = [
                'contracts' => $this->db->fetchAllAssociative('SELECT version, created_at FROM task_contract WHERE task_id = ? ORDER BY version DESC'.$suffix, [$id]),
                'dependencies' => $this->db->fetchAllAssociative("SELECT t.id, t.title, t.status, 'requires' AS direction FROM task_dependency d JOIN task t ON t.id = d.depends_on_id WHERE d.task_id = ? UNION ALL SELECT t.id, t.title, t.status, 'required_by' AS direction FROM task_dependency d JOIN task t ON t.id = d.task_id WHERE d.depends_on_id = ? ORDER BY direction, id".$suffix, [$id, $id]),
                'events' => $this->db->fetchAllAssociative('SELECT id, from_state, to_state, version, actor_id, created_at FROM state_event WHERE task_id = ? ORDER BY version DESC'.$suffix, [$id]),
                'git' => $this->db->fetchAllAssociative('SELECT a.id, a.repository_id, a.sha, a.session_id, a.agent_id, a.source, a.confidence, a.verification_status, a.observed_at, LEFT(COALESCE(e.metadata->>\'subject\', SPLIT_PART(e.metadata->>\'message\', CHR(10), 1)), 240) AS subject FROM git_task_association a LEFT JOIN git_event e ON e.repository_id = a.repository_id AND e.sha = a.sha AND e.kind = \'commit\' WHERE a.task_id = ? ORDER BY a.observed_at DESC, a.id'.$suffix, [$id]),
                'receipts' => $this->db->fetchAllAssociative('SELECT id, session_id, contract_version, lease_generation, status, hash, created_at FROM completion_receipt WHERE task_id = ? ORDER BY created_at DESC, id'.$suffix, [$id]),
                'jobs' => $this->db->fetchAllAssociative('SELECT j.id, j.message_type, j.status, j.attempts, LEFT(j.error, 4000) AS error, j.created_at, j.finished_at FROM background_job j JOIN outbox_event o ON o.id = j.id WHERE o.aggregate_id = ? OR o.aggregate_id IN (SELECT id FROM completion_receipt WHERE task_id = ?) ORDER BY j.created_at DESC, j.id'.$suffix, [$id, $id]),
            ];
            $hasNext = [];
            foreach ($sections as $name => &$rows) {
                $hasNext[$name] = count($rows) > self::PAGE_SIZE;
                $rows = array_slice($rows, 0, self::PAGE_SIZE);
            }
            unset($rows);

            return ['task' => $task, 'contract' => $contract, 'lease' => $lease ?: null, 'page' => $query->page, 'page_size' => self::PAGE_SIZE, 'has_next' => $hasNext, 'observed_at' => gmdate(DATE_ATOM)] + $sections;
        });
    }
}
