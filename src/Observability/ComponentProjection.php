<?php

namespace App\Observability;

use App\Runtime\RuntimeMonitor;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Focused reads deliberately exclude contracts, Git graphs and historical receipts. */
final readonly class ComponentProjection
{
    public function __construct(private Connection $db, private OperationsOverview $overview, private RuntimeMonitor $runtime) {}

    public function context(ObservationFilter $filter): ?string
    {
        $project = $filter->project ?? ($this->db->fetchOne('SELECT id FROM project ORDER BY id LIMIT 1') ?: null);
        if (null !== $project && !$this->db->fetchOne('SELECT id FROM project WHERE id = ?', [$project])) {
            throw new NotFoundHttpException('Project not found.');
        }
        if (null !== $filter->task && !$this->db->fetchOne('SELECT id FROM task WHERE id = ? AND project_id = ?', [$filter->task, $project])) {
            throw new NotFoundHttpException('Task not found in selected project.');
        }

        return $project;
    }

    public function read(string $component, ObservationFilter $filter): array
    {
        if (!in_array($component, ['operations', 'work'], true)) {
            throw new NotFoundHttpException('Unknown console component.');
        }

        return $this->db->transactional(function () use ($component, $filter): array {
            $this->db->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $project = $this->context($filter);
            $snapshot = ['project' => $project, 'page' => $filter->page, 'page_size' => DashboardProjection::PAGE_SIZE, 'task' => null, 'operations' => $this->overview->read($project)];
            if ('operations' === $component) {
                $snapshot['queues'] = $this->runtime->queues();
                $snapshot['health'] = $this->runtime->health();
            } else {
                $offset = $filter->page * DashboardProjection::PAGE_SIZE;
                $tasks = $this->db->fetchAllAssociative("SELECT t.id, t.title, t.status, t.version, t.contract_version, a.name AS agent, l.expires_at AS lease_expires_at, l.expires_at <= CURRENT_TIMESTAMP AT TIME ZONE 'UTC' AS lease_expired FROM task t LEFT JOIN task_lease l ON l.task_id = t.id LEFT JOIN agent_session s ON s.id = l.session_id LEFT JOIN agent a ON a.id = s.agent_id WHERE t.project_id = ? ORDER BY t.id LIMIT 26 OFFSET ".$offset, [$project]);
                $snapshot['has_next'] = count($tasks) > DashboardProjection::PAGE_SIZE;
                $snapshot['work_groups'] = array_fill_keys(array_keys(OperationsOverview::GROUPS), []);
                foreach (array_slice($tasks, 0, DashboardProjection::PAGE_SIZE) as $task) {
                    foreach (OperationsOverview::GROUPS as $group => $states) {
                        if (in_array($task['status'], $states, true)) {
                            $snapshot['work_groups'][$group][] = $task;
                            break;
                        }
                    }
                }
            }

            return $snapshot;
        });
    }
}
