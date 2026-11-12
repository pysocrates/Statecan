<?php

namespace App\Git;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class GitCatalog
{
    public function __construct(private readonly Connection $db, private readonly LocalGitReader $reader)
    {
    }

    public function register(string $projectId, string $path, string $key): array
    {
        $path = $this->reader->canonicalPath($path);

        return $this->db->transactional(function () use ($projectId, $path, $key): array {
            if (!$this->db->fetchOne('SELECT id FROM project WHERE id = ?', [$projectId])) {
                throw new NotFoundHttpException('Project not found.');
            }
            $this->db->executeStatement("INSERT INTO git_repository (id, project_id, path, created_at, refs) VALUES (?, ?, ?, ?, '{}') ON CONFLICT (path) DO NOTHING", [GitId::new(), $projectId, $path, gmdate('Y-m-d H:i:s')]);
            $repository = $this->db->fetchAssociative('SELECT * FROM git_repository WHERE path = ?', [$path]);
            if ($repository['project_id'] !== $projectId) {
                throw new ConflictHttpException('Repository is already registered to another project.');
            }
            $event = $this->schedule($repository['id'], 'register:'.$projectId.':'.$key, hash('sha256', $projectId."\0".$path));

            return ['id' => $repository['id'], 'projectId' => $projectId, 'jobId' => $event];
        });
    }

    public function sync(string $repositoryId, string $key): array
    {
        return $this->db->transactional(function () use ($repositoryId, $key): array {
            $this->repository($repositoryId);

            return ['id' => $repositoryId, 'jobId' => $this->schedule($repositoryId, 'sync:'.$repositoryId.':'.$key, $repositoryId)];
        });
    }

    public function repository(string $id): array
    {
        return $this->db->fetchAssociative('SELECT * FROM git_repository WHERE id = ?', [$id]) ?: throw new NotFoundHttpException('Repository not found.');
    }

    private function schedule(string $id, string $key, string $fingerprint): string
    {
        $hex = hash('sha256', 'git.sync_requested:'.$key);
        $eventId = substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
        $payload = json_encode(['repositoryId' => $id, 'fingerprint' => $fingerprint], JSON_THROW_ON_ERROR);
        $this->db->executeStatement('INSERT INTO outbox_event (id, type, aggregate_id, payload, created_at) VALUES (?, ?, ?, ?, ?) ON CONFLICT (id) DO NOTHING', [$eventId, 'git.sync_requested', $id, $payload, gmdate('Y-m-d H:i:s')]);
        $stored = $this->db->fetchAssociative('SELECT aggregate_id, payload FROM outbox_event WHERE id = ?', [$eventId]);
        if ($stored['aggregate_id'] !== $id || json_decode($stored['payload'], true, flags: JSON_THROW_ON_ERROR)['fingerprint'] !== $fingerprint) {
            throw new ConflictHttpException('Idempotency key was already used with different input.');
        }

        return $eventId;
    }
}
