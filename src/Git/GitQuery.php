<?php

namespace App\Git;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class GitQuery
{
    public function __construct(private readonly Connection $db, private readonly GitCatalog $catalog)
    {
    }

    public function events(string $repositoryId, int $limit = 50, int $offset = 0): array
    {
        $repository = $this->catalog->repository($repositoryId);
        $rows = $this->db->fetchAllAssociative('SELECT id, kind, sha, metadata, observed_at FROM git_event WHERE repository_id = ? ORDER BY observed_at DESC, id DESC LIMIT '.min(200, max(1, $limit)).' OFFSET '.max(0, $offset), [$repositoryId]);
        foreach ($rows as &$row) {
            $row['metadata'] = json_decode($row['metadata'], true, flags: JSON_THROW_ON_ERROR);
        }

        return ['repositoryId' => $repositoryId, 'refs' => json_decode($repository['refs'], true, flags: JSON_THROW_ON_ERROR), 'observedAt' => $repository['observed_at'], 'events' => $rows, 'limit' => min(200, max(1, $limit)), 'offset' => max(0, $offset)];
    }

    public function graph(string $repositoryId, int $limit = 50): array
    {
        $this->catalog->repository($repositoryId);
        $rows = $this->db->fetchAllAssociative("SELECT sha, metadata FROM git_event WHERE repository_id = ? AND kind IN ('commit', 'merge') ORDER BY observed_at DESC, id DESC LIMIT ".min(200, max(1, $limit)), [$repositoryId]);
        $lines = ['graph TD'];
        $nodes = [];
        $parents = [];
        foreach ($rows as $row) {
            // Node IDs and labels derive only from indexed hexadecimal commit IDs.
            $sha = $row['sha'];
            if (!preg_match('/^[a-f0-9]{40,64}$/D', $sha)) {
                continue;
            }
            $nodes[$sha] = true;
            $lines[] = '  c'.$sha.'["'.substr($sha, 0, 12).'"]';
            $parents[$sha] = json_decode($row['metadata'], true, flags: JSON_THROW_ON_ERROR)['parents'] ?? [];
        }
        foreach ($parents as $sha => $list) {
            foreach ($list as $parent) {
                if (isset($nodes[$parent])) {
                    $lines[] = '  c'.$parent.' --> c'.$sha;
                }
            }
        }

        return ['repositoryId' => $repositoryId, 'mermaid' => implode("\n", $lines), 'count' => count($nodes), 'limit' => min(200, max(1, $limit))];
    }

    public function task(string $taskId, int $limit = 50): array
    {
        if (!$this->db->fetchOne('SELECT id FROM task WHERE id = ?', [$taskId])) {
            throw new NotFoundHttpException('Task not found.');
        }

        return ['taskId' => $taskId, 'associations' => $this->db->fetchAllAssociative('SELECT repository_id, sha, task_id, session_id, agent_id, source, confidence, verification_status, observed_at FROM git_task_association WHERE task_id = ? ORDER BY observed_at DESC, id DESC LIMIT '.min(200, max(1, $limit)), [$taskId])];
    }
}
