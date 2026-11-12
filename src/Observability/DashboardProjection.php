<?php

namespace App\Observability;

use App\Git\GitQuery;
use App\Runtime\RuntimeMonitor;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Bounded read model; never infers current state from the event ledger. */
final class DashboardProjection
{
    public const PAGE_SIZE = 25;
    public const STATES = ['backlog', 'ready', 'claimed', 'running', 'blocked', 'awaiting_review', 'completion_pending_evidence', 'completed', 'failed', 'cancelled'];

    public function __construct(
        private readonly Connection $db,
        private readonly RuntimeMonitor $runtime,
        private readonly GitQuery $git,
    ) {
    }

    public function snapshot(ObservationFilter $filter): array
    {
        return $this->db->transactional(function () use ($filter): array {
            $this->db->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $projects = $this->db->fetchAllAssociative('SELECT id, name FROM project ORDER BY id LIMIT 100');
            $project = $filter->project ?? ($projects[0]['id'] ?? null);
            if (null !== $project && !$this->db->fetchOne('SELECT id FROM project WHERE id = ?', [$project])) {
                throw new NotFoundHttpException('Project not found.');
            }
            $offset = $filter->page * self::PAGE_SIZE;
            $tasks = $this->db->fetchAllAssociative('SELECT id, title, status, version, contract_version FROM task WHERE project_id = ? ORDER BY id LIMIT 26 OFFSET '.$offset, [$project]);
            $hasNext = count($tasks) > self::PAGE_SIZE;
            $tasks = array_slice($tasks, 0, self::PAGE_SIZE);
            $kanban = array_fill_keys(self::STATES, []);
            foreach ($tasks as $task) {
                $kanban[$task['status']][] = $task;
            }
            $inspector = null;
            if (null !== $filter->task) {
                $inspector = $this->db->fetchAssociative('SELECT id, title, status, version, contract_version FROM task WHERE id = ? AND project_id = ?', [$filter->task, $project]);
                if (!$inspector) {
                    throw new NotFoundHttpException('Task not found in selected project.');
                }
                $inspector['contracts'] = $this->db->fetchAllAssociative('SELECT version, document FROM task_contract WHERE task_id = ? ORDER BY version DESC LIMIT 25', [$filter->task]);
                $inspector['events'] = $this->db->fetchAllAssociative('SELECT id, from_state, to_state, version, actor_id, created_at FROM state_event WHERE task_id = ? ORDER BY version DESC LIMIT 25 OFFSET '.$offset, [$filter->task]);
                $inspector['receipts'] = $this->receipts('AND r.task_id = ?', [$project, $filter->task], $offset);
                $inspector['git'] = $this->git->task($filter->task, self::PAGE_SIZE);
                $inspector['provenance_graph'] = $this->provenanceGraph($filter->task, $inspector['git']['associations']);
            }
            $dependencies = $this->db->fetchAllAssociative('SELECT d.task_id, d.depends_on_id FROM task_dependency d JOIN task t ON t.id = d.task_id WHERE t.project_id = ? ORDER BY d.task_id, d.depends_on_id LIMIT 25 OFFSET '.$offset, [$project]);
            $sessions = $this->db->fetchAllAssociative('SELECT s.id, a.name AS agent, s.created_at, s.last_heartbeat_at FROM agent_session s JOIN agent a ON a.id = s.agent_id WHERE EXISTS (SELECT 1 FROM task_lease l JOIN task t ON t.id = l.task_id WHERE l.session_id = s.id AND t.project_id = ?) ORDER BY s.last_heartbeat_at DESC, s.id LIMIT 25 OFFSET '.$offset, [$project]);
            $repositories = $this->db->fetchAllAssociative('SELECT id, refs, observed_at FROM git_repository WHERE project_id = ? ORDER BY id LIMIT 25 OFFSET '.$offset, [$project]);
            foreach ($repositories as &$repository) {
                $repository['events'] = $this->git->events($repository['id'], self::PAGE_SIZE);
                $repository['graph'] = $this->git->graph($repository['id'], self::PAGE_SIZE);
            }
            unset($repository);

            return [
                'projects' => $projects, 'project' => $project, 'page' => $filter->page,
                'has_next' => $hasNext, 'page_size' => self::PAGE_SIZE, 'kanban' => $kanban,
                'task' => $inspector, 'dependencies' => $dependencies,
                'dependency_graph' => $this->dependencyGraph($dependencies),
                'sessions' => $sessions, 'repositories' => $repositories,
                'receipts' => $this->receipts('', [$project], $offset),
                'jobs' => $this->runtime->jobs(self::PAGE_SIZE),
                'queues' => $this->runtime->queues(), 'health' => $this->runtime->health(),
            ];
        });
    }

    public function receipt(string $id): array
    {
        $receipt = $this->db->fetchAssociative('SELECT id, task_id, session_id, contract_version, lease_generation, status, hash, canonical_content, created_at FROM completion_receipt WHERE id = ?', [$id]);
        if (!$receipt) {
            throw new NotFoundHttpException('Receipt not found.');
        }

        return $receipt;
    }

    private function provenanceGraph(string $taskId, array $associations): string
    {
        $task = 't'.preg_replace('/[^a-f0-9]/i', '', $taskId);
        $lines = ['flowchart LR', $task.'["Task"]'];
        foreach ($associations as $association) {
            $sha = $association['sha'];
            if (preg_match('/^[a-f0-9]{40,64}$/D', $sha)) {
                $lines[] = $task.' --> c'.$sha.'["'.substr($sha, 0, 12).'"]';
            }
        }

        return implode("\n", array_unique($lines));
    }

    private function receipts(string $condition, array $parameters, int $offset): array
    {
        return $this->db->fetchAllAssociative('SELECT r.id, r.task_id, r.session_id, r.contract_version, r.lease_generation, r.status, r.hash, r.created_at FROM completion_receipt r JOIN task t ON t.id = r.task_id WHERE t.project_id = ? '.$condition.' ORDER BY r.created_at DESC, r.id LIMIT 25 OFFSET '.$offset, $parameters);
    }

    private function dependencyGraph(array $dependencies): string
    {
        $graph = ['flowchart LR'];
        foreach ($dependencies as $edge) {
            // Only generated hex identifiers enter Mermaid syntax; titles remain in escaped HTML.
            $task = preg_replace('/[^a-f0-9]/i', '', $edge['task_id']);
            $dependency = preg_replace('/[^a-f0-9]/i', '', $edge['depends_on_id']);
            $graph[] = 't'.$dependency.' --> t'.$task;
        }

        return implode("\n", $graph);
    }
}
