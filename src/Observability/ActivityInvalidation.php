<?php

namespace App\Observability;

/** Stable component/entity routing, not a reconstruction of application state. */
final class ActivityInvalidation
{
    public const COMPONENTS = ['operations', 'work', 'git', 'history', 'runtime', 'inspector', 'projects'];
    private const ROUTES = [
        'project' => self::COMPONENTS,
        'task' => ['operations', 'work', 'history', 'inspector'],
        'task_contract' => ['work', 'history', 'inspector'],
        'task_dependency' => ['work', 'git', 'inspector'],
        'state_event' => ['operations', 'work', 'history', 'inspector'],
        'agent' => ['operations', 'work', 'history', 'inspector'],
        'agent_session' => ['operations', 'history', 'inspector'],
        'task_lease' => ['operations', 'work', 'history', 'inspector'],
        'completion_receipt' => ['operations', 'history', 'inspector'],
        'git_repository' => ['git', 'history', 'inspector'],
        'git_event' => ['git', 'history', 'inspector'],
        'git_task_association' => ['git', 'history', 'inspector'],
        'background_job' => ['operations', 'runtime', 'inspector'],
        'worker_process' => ['operations', 'runtime'],
        'messenger_messages' => ['operations', 'runtime'],
        'outbox_event' => ['operations', 'runtime'],
    ];
    private const TYPES = ['agent_session' => 'session', 'task_lease' => 'lease', 'completion_receipt' => 'receipt', 'task_contract' => 'contract', 'task_dependency' => 'dependency', 'state_event' => 'transition', 'git_repository' => 'repository', 'git_event' => 'git', 'git_task_association' => 'association'];

    public function batch(array $changes, ?string $project, bool $reconcile = false): array
    {
        $components = [];
        $entities = [];
        foreach ($changes as $change) {
            if (isset($change['projectId']) && null !== $project && $change['projectId'] !== $project && 'project' !== ($change['table'] ?? '')) {
                continue;
            }
            $table = $change['table'] ?? '';
            if (!isset(self::ROUTES[$table])) {
                $reconcile = true;
                continue;
            }
            array_push($components, ...self::ROUTES[$table]);
            if (isset($change['id']) && is_string($change['id'])) {
                $entities[$table.':'.$change['id']] = [
                    'type' => self::TYPES[$table] ?? $table, 'id' => $change['id'],
                    'revision' => (string) ($change['revision'] ?? ''),
                    'operation' => $change['operation'] ?? null,
                    'taskId' => $change['taskId'] ?? ('task' === $table ? $change['id'] : null),
                    'sessionId' => $change['sessionId'] ?? ('agent_session' === $table ? $change['id'] : null),
                    'projectId' => $change['projectId'] ?? null,
                ];
            }
        }

        return ['refresh' => $reconcile || [] !== $components, 'components' => $reconcile ? self::COMPONENTS : array_values(array_unique($components)), 'entities' => array_values($entities), 'reconcile' => $reconcile];
    }
}
