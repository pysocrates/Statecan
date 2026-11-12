<?php

namespace App\Observability;

use App\Dto\Observability\GitHistoryQuery;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Focused, read-only projections. Git evidence never authenticates authorship. */
final readonly class GitHistoryProjection
{
    public const PAGE_SIZE = 25;
    public const GRAPH_LIMIT = 50;

    public function __construct(private Connection $db, private GraphPreview $graphs)
    {
    }

    public function git(GitHistoryQuery $filter, ?string $commitSha = null): array
    {
        $project = $this->project($filter->project);
        $repositories = $this->db->fetchAllAssociative('SELECT id, observed_at FROM git_repository WHERE project_id = ? ORDER BY id LIMIT 100', [$project]);
        $repositoryId = $filter->repository ?: ($repositories[0]['id'] ?? null);
        $repository = null;
        if ($repositoryId) {
            $repository = $this->db->fetchAssociative('SELECT id, observed_at, refs FROM git_repository WHERE id = ? AND project_id = ?', [$repositoryId, $project]);
            if (!$repository) {
                throw new NotFoundHttpException('Repository not found in this project.');
            }
            $repository['refs'] = json_decode($repository['refs'], true, flags: JSON_THROW_ON_ERROR);
        }
        $prefix = '';
        $parameters = [];
        $condition = 'e.repository_id = ?';
        if ('' !== $filter->branch) {
            $tip = $repository['refs'][$filter->branch] ?? null;
            if (null === $tip) {
                throw new NotFoundHttpException('Observed ref not found.');
            }
            // Bound traversal as well as the returned graph; never execute Git from a read request.
            $prefix = "WITH RECURSIVE ancestry(sha) AS (SELECT CAST(? AS text) UNION SELECT jsonb_array_elements_text(e.metadata->'parents') FROM git_event e JOIN ancestry a ON e.sha = a.sha WHERE e.repository_id = ? AND e.kind IN ('commit', 'merge')) ";
            $parameters = [$tip, $repositoryId];
            $condition .= ' AND e.sha IN (SELECT sha FROM ancestry LIMIT 5000)';
        }
        $parameters[] = $repositoryId;
        if (null !== $commitSha) {
            $condition .= " AND e.sha = ? AND e.kind IN ('commit', 'merge')";
            $parameters[] = $commitSha;
        }
        if ('' !== trim($filter->q)) {
            $condition .= " AND strpos(lower(concat_ws(' ', e.sha, e.metadata->>'message', e.metadata->>'author', e.metadata->>'ref')), lower(?)) > 0";
            $parameters[] = trim($filter->q);
        }
        if ('all' !== $filter->activity) {
            $condition .= 'ref' === $filter->activity ? " AND e.kind IN ('ref_created', 'ref_deleted', 'ref_updated')" : ' AND e.kind = ?';
            if ('ref' !== $filter->activity) {
                $parameters[] = $filter->activity;
            }
        }
        $fields = "e.id, e.kind, e.sha, e.observed_at, left(e.metadata->>'message', 4000) AS message, left(e.metadata->>'author', 200) AS author, e.metadata->>'authoredAt' AS authored_at, e.metadata->>'reverts' AS reverts, e.metadata->>'ref' AS ref, e.metadata->>'from' AS old_ref, e.metadata->>'to' AS new_ref, e.metadata->'parents' AS parents, (SELECT jsonb_agg(f) FROM jsonb_array_elements(COALESCE(e.metadata->'files', '[]'::jsonb)) WITH ORDINALITY AS files(f,n) WHERE n <= 100) AS files, jsonb_array_length(COALESCE(e.metadata->'files', '[]'::jsonb)) AS file_count";
        $events = $this->db->fetchAllAssociative($prefix.'SELECT '.$fields.' FROM git_event e WHERE '.$condition.' ORDER BY e.observed_at DESC, e.id DESC LIMIT 26 OFFSET '.($filter->page * self::PAGE_SIZE), $parameters);
        $hasNext = count($events) > self::PAGE_SIZE;
        $events = array_slice($events, 0, self::PAGE_SIZE);
        $shas = array_values(array_filter(array_column($events, 'sha')));
        $associations = [];
        if ($shas) {
            $associations = $this->db->fetchAllAssociative('SELECT g.sha, g.task_id, t.title, g.session_id, a.name AS agent, g.confidence, g.source, g.verification_status, g.observed_at FROM git_task_association g JOIN task t ON t.id = g.task_id LEFT JOIN agent_session s ON s.id = g.session_id LEFT JOIN agent a ON a.id = s.agent_id WHERE g.repository_id = ? AND g.sha IN (?) ORDER BY g.observed_at DESC, g.id LIMIT 200', [$repositoryId, $shas], [1 => ArrayParameterType::STRING]);
        }
        foreach ($events as &$event) {
            $event['files'] = json_decode($event['files'] ?? '[]', true, flags: JSON_THROW_ON_ERROR);
            $event['parents'] = json_decode($event['parents'] ?? '[]', true, flags: JSON_THROW_ON_ERROR);
            $event['associations'] = array_values(array_filter($associations, static fn (array $a): bool => $a['sha'] === $event['sha']));
        }
        unset($event);
        $graphRows = null !== $commitSha ? [] : $this->db->fetchAllAssociative($prefix."SELECT e.sha, e.metadata->'parents' AS parents, left(e.metadata->>'message', 160) AS message FROM git_event e WHERE ".$condition." AND e.kind IN ('commit', 'merge') ORDER BY e.observed_at DESC, e.id DESC LIMIT 50", $parameters);
        $nodes = [];
        $lines = ['graph LR'];
        foreach ($graphRows as $row) {
            if (preg_match('/^[a-f0-9]{40,64}$/D', $row['sha'])) {
                $nodes[$row['sha']] = $row;
                $lines[] = 'c'.$row['sha'].'["'.substr($row['sha'], 0, 12).'"]';
            }
        }
        foreach ($nodes as $sha => $row) {
            foreach (json_decode($row['parents'] ?? '[]', true, flags: JSON_THROW_ON_ERROR) as $parent) {
                if (isset($nodes[$parent])) {
                    $lines[] = 'c'.$parent.' --> c'.$sha;
                }
            }
        }
        $mermaid = implode("\n", $lines);
        $graph = $this->graphs->layout($mermaid);
        // Fit small histories without enlarging a single commit into a full-width card.
        $dx = max(0, (760 - $graph['width']) / 2);
        $dy = max(0, (280 - $graph['height']) / 2);
        foreach ($graph['nodes'] as &$node) {
            $node['x'] += $dx;
            $node['y'] += $dy;
        }
        unset($node);
        $graph['width'] = max(760, $graph['width']);
        $graph['height'] = max(280, $graph['height']);

        return ['project' => $project, 'repositories' => $repositories, 'repository' => $repository, 'events' => $events, 'graph' => $graph, 'mermaid' => $mermaid, 'page' => $filter->page, 'has_next' => $hasNext, 'page_size' => self::PAGE_SIZE, 'filter' => $filter, 'graph_limit' => self::GRAPH_LIMIT];
    }

    public function history(GitHistoryQuery $filter): array
    {
        $project = $this->project($filter->project);
        $parameters = [$project];
        [$from, $fields, $time, $search, $status] = match ($filter->kind) {
            'sessions' => ["agent_session s JOIN agent a ON a.id=s.agent_id JOIN LATERAL (SELECT DISTINCT t.id, t.title FROM task t WHERE t.project_id = ? AND (EXISTS (SELECT 1 FROM state_event e WHERE e.task_id=t.id AND e.metadata->>'sessionId'=s.id) OR EXISTS (SELECT 1 FROM completion_receipt r WHERE r.task_id=t.id AND r.session_id=s.id) OR EXISTS (SELECT 1 FROM task_lease l WHERE l.task_id=t.id AND l.session_id=s.id) OR EXISTS (SELECT 1 FROM git_task_association g WHERE g.task_id=t.id AND g.session_id=s.id)) ORDER BY t.id LIMIT 100) t ON TRUE", "s.id, s.id AS session_id, a.name AS agent, s.created_at AS occurred_at, s.last_heartbeat_at, t.id AS task_id, t.title, 'registered' AS status", 's.created_at', "concat_ws(' ',s.id,a.name,t.title,t.id)", "'registered'"],
            'receipts' => ['completion_receipt r JOIN task t ON t.id=r.task_id', 'r.id,r.task_id,t.title,r.session_id,r.status,r.hash,r.contract_version,r.lease_generation,r.created_at AS occurred_at', 'r.created_at', "concat_ws(' ',r.id,t.title,t.id,r.hash,r.session_id)", 'r.status'],
            'contracts' => ['task_contract c JOIN task t ON t.id=c.task_id', "c.id,c.task_id,t.title,c.version,c.created_at AS occurred_at,'versioned' AS status,c.document", 'c.created_at', "concat_ws(' ',t.title,t.id,c.document->>'objective')", "'versioned'"],
            'git' => ['git_task_association g JOIN task t ON t.id=g.task_id', 'g.id,g.task_id,t.title,g.sha,g.repository_id,g.session_id,g.confidence,g.source,g.verification_status AS status,g.observed_at AS occurred_at', 'g.observed_at', "concat_ws(' ',t.title,t.id,g.sha,g.session_id)", 'g.verification_status'],
            default => ['state_event e JOIN task t ON t.id=e.task_id', "e.id,e.task_id,t.title,e.from_state,e.to_state AS status,e.version,e.actor_id,e.created_at AS occurred_at,e.metadata->>'sessionId' AS session_id", 'e.created_at', "concat_ws(' ',t.title,t.id,e.actor_id,e.from_state,e.to_state,e.metadata->>'sessionId')", 'e.to_state'],
        };
        $where = 'sessions' === $filter->kind ? '1=1' : 't.project_id = ?';
        if ($filter->task) {
            $where .= ' AND t.id = ?';
            $parameters[] = $filter->task;
        }
        if ('' !== trim($filter->q)) {
            $where .= ' AND strpos(lower('.$search.'),lower(?)) > 0';
            $parameters[] = trim($filter->q);
        }
        if ('' !== $filter->status) {
            $where .= ' AND '.$status.' = ?';
            $parameters[] = $filter->status;
        }
        $rows = $this->db->fetchAllAssociative('SELECT '.$fields.' FROM '.$from.' WHERE '.$where.' ORDER BY '.$time.' DESC, id DESC, t.id LIMIT 26 OFFSET '.($filter->page * self::PAGE_SIZE), $parameters);
        $hasNext = count($rows) > self::PAGE_SIZE;
        $rows = array_slice($rows, 0, self::PAGE_SIZE);
        foreach ($rows as &$row) {
            if (isset($row['document'])) {
                $row['document'] = json_decode($row['document'], true, flags: JSON_THROW_ON_ERROR);
            }
        }
        unset($row);
        $statuses = match ($filter->kind) {
            'receipts' => ['pending', 'verified', 'inconsistent'],
            'sessions' => ['registered'],
            'contracts' => ['versioned'],
            'git' => ['unverified', 'identities_validated'],
            default => DashboardProjection::STATES,
        };

        return ['project' => $project, 'rows' => $rows, 'kind' => $filter->kind, 'filter' => $filter, 'statuses' => $statuses, 'page' => $filter->page, 'has_next' => $hasNext, 'page_size' => self::PAGE_SIZE];
    }

    private function project(?string $id): ?string
    {
        $id = $id ?: ($this->db->fetchOne('SELECT id FROM project ORDER BY id LIMIT 1') ?: null);
        if ($id && !$this->db->fetchOne('SELECT id FROM project WHERE id = ?', [$id])) {
            throw new NotFoundHttpException('Project not found.');
        }

        return $id;
    }
}
