<?php

namespace App\Git;

use Doctrine\DBAL\Connection;
use App\Runtime\RuntimeLockFactory;

final class GitIndexer
{
    public function __construct(private readonly Connection $db, private readonly LocalGitReader $reader, private readonly GitCatalog $catalog, private readonly RuntimeLockFactory $lockFactory)
    {
    }

    public function sync(string $repositoryId): void
    {
        $lock = $this->lockFactory->createLock('git-index:'.$repositoryId, 600);
        if (!$lock->acquire()) {
            throw new \RuntimeException('Repository indexing is already in progress.');
        }
        try {
            $repository = $this->catalog->repository($repositoryId);
            // No database transaction is held during external Git operations.
            $snapshot = $this->reader->snapshot($repository['path'], static fn () => $lock->refresh());
            $this->db->transactional(function () use ($repositoryId, $snapshot): void {
                $repository = $this->db->fetchAssociative('SELECT * FROM git_repository WHERE id = ? FOR UPDATE', [$repositoryId]);
                $observedAt = gmdate('Y-m-d H:i:s');
                foreach ($snapshot['commits'] as $sha => $commit) {
                    $this->event($repositoryId, 'commit:'.$sha, count($commit['parents']) > 1 ? 'merge' : 'commit', $sha, $commit, $observedAt);
                    $this->correlate($repository, $sha, $commit['trailers'], $observedAt);
                }
                $old = json_decode($repository['refs'], true, flags: JSON_THROW_ON_ERROR);
                foreach (array_unique([...array_keys($old), ...array_keys($snapshot['refs'])]) as $ref) {
                    $from = $old[$ref] ?? null;
                    $to = $snapshot['refs'][$ref] ?? null;
                    if ($from === $to) {
                        continue;
                    }
                    $kind = null === $from ? 'ref_created' : (null === $to ? 'ref_deleted' : 'ref_updated');
                    // A unique observation ID preserves repeated A->B->A ref movements.
                    $this->event($repositoryId, 'ref:'.GitId::new(), $kind, $to, ['ref' => $ref, 'from' => $from, 'to' => $to, 'source' => 'local_git'], $observedAt);
                }
                $this->db->executeStatement('UPDATE git_repository SET refs = ?, observed_at = ? WHERE id = ?', [json_encode($snapshot['refs'], JSON_THROW_ON_ERROR), $observedAt, $repositoryId]);
            });
        } finally {
            $lock->release();
        }
    }

    private function event(string $repository, string $key, string $kind, ?string $sha, array $metadata, string $at): void
    {
        $this->db->executeStatement('INSERT INTO git_event (id, repository_id, event_key, kind, sha, metadata, observed_at) VALUES (?, ?, ?, ?, ?, ?, ?) ON CONFLICT (repository_id, event_key) DO NOTHING', [GitId::new(), $repository, $key, $kind, $sha, json_encode($metadata, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), $at]);
    }

    private function correlate(array $repository, string $sha, array $trailers, string $at): void
    {
        foreach (array_unique($trailers['task'] ?? []) as $taskId) {
            if (strlen($taskId) > 36 || !$this->db->fetchOne('SELECT id FROM task WHERE id = ? AND project_id = ?', [$taskId, $repository['project_id']])) {
                continue;
            }
            $session = count($trailers['session'] ?? []) === 1 ? $trailers['session'][0] : null;
            $agent = count($trailers['agent'] ?? []) === 1 ? $trailers['agent'][0] : null;
            $validSession = null !== $session && strlen($session) <= 36 ? $this->db->fetchAssociative('SELECT id, agent_id FROM agent_session WHERE id = ?', [$session]) : false;
            // Valid identities are still self-reported: never infer authenticated authorship.
            $verified = $validSession && null !== $agent && $validSession['agent_id'] === $agent;
            $this->db->executeStatement('INSERT INTO git_task_association (id, repository_id, sha, task_id, session_id, agent_id, source, confidence, verification_status, observed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT (repository_id, sha, task_id) DO UPDATE SET session_id = EXCLUDED.session_id, agent_id = EXCLUDED.agent_id, verification_status = EXCLUDED.verification_status', [GitId::new(), $repository['id'], $sha, $taskId, $validSession ? $session : null, $verified ? $agent : null, 'git_trailer', 'self_reported', $verified ? 'identities_validated' : 'unverified', $at]);
        }
    }
}
