<?php

namespace App\Tests\Git;

use App\Entity\Project;
use App\Entity\Task;
use App\Git\GitCatalog;
use App\Git\GitIndexer;
use App\Git\GitQuery;
use App\Runtime\OutboxDispatcher;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Process\Process;

final class GitWorkflowTest extends KernelTestCase
{
    public function testRepositoryIsQueuedIndexedAndCorrelated(): void
    {
        $path = sys_get_temp_dir().'/statecan-git-'.bin2hex(random_bytes(6));
        mkdir($path);
        $previous = $_ENV['STATECAN_GIT_ROOT'] ?? null;
        $_ENV['STATECAN_GIT_ROOT'] = $_SERVER['STATECAN_GIT_ROOT'] = $path;
        static::bootKernel();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $db = $container->get(Connection::class);
        $project = new Project('Git workflow');
        $task = new Task($project, 'Correlate a commit');
        $em->persist($project);
        $em->persist($task);
        $em->flush();
        $repositoryId = $jobId = null;
        try {
            (new Process(['git', 'init', '-b', 'main', $path]))->mustRun();
            file_put_contents($path.'/hello.txt', 'hello');
            (new Process(['git', '-C', $path, 'add', 'hello.txt']))->mustRun();
            (new Process(['git', '-C', $path, '-c', 'user.name=Test', '-c', 'user.email=test@example.com', 'commit', '-m', "Initial\n\nStatecan-Task: ".$task->getId()]))->mustRun();
            $catalog = $container->get(GitCatalog::class);
            $registration = $catalog->register($project->getId(), $path, 'workflow');
            $repositoryId = $registration['id'];
            $jobId = $registration['jobId'];
            self::assertSame($registration, $catalog->register($project->getId(), $path, 'workflow'));
            // Exercise durable enqueue and the actual Messenger handler/listener.
            $container->get(OutboxDispatcher::class)->dispatch();
            $transport = $container->get('messenger.transport.git_indexing');
            $handled = false;
            foreach ($transport->get() as $envelope) {
                $container->get(MessageBusInterface::class)->dispatch($envelope->with(new ReceivedStamp('git_indexing')));
                $transport->ack($envelope);
                $handled = true;
            }
            self::assertTrue($handled);
            $query = $container->get(GitQuery::class);
            $events = $query->events($repositoryId);
            self::assertCount(2, $events['events']);
            self::assertArrayHasKey('refs/heads/main', $events['refs']);
            $associations = $query->task($task->getId())['associations'];
            self::assertCount(1, $associations);
            self::assertSame('unverified', $associations[0]['verification_status']);
            self::assertSame('self_reported', $associations[0]['confidence']);
            self::assertStringContainsString(substr($associations[0]['sha'], 0, 12), $query->graph($repositoryId)['mermaid']);
            $container->get(GitIndexer::class)->sync($repositoryId);
            self::assertCount(2, $query->events($repositoryId)['events']);
            self::assertSame('completed', $db->fetchOne('SELECT status FROM background_job WHERE id = ?', [$jobId]));
        } finally {
            if (null !== $repositoryId) {
                $db->delete('git_task_association', ['repository_id' => $repositoryId]);
                $db->delete('git_event', ['repository_id' => $repositoryId]);
                $db->delete('git_repository', ['id' => $repositoryId]);
            }
            if (null !== $jobId) {
                $db->delete('outbox_event', ['id' => $jobId]);
                $db->delete('background_job', ['id' => $jobId]);
            }
            $em->clear();
            $db->delete('task', ['id' => $task->getId()]);
            $db->delete('project', ['id' => $project->getId()]);
            static::ensureKernelShutdown();
            if (null === $previous) {
                unset($_ENV['STATECAN_GIT_ROOT'], $_SERVER['STATECAN_GIT_ROOT']);
            } else {
                $_ENV['STATECAN_GIT_ROOT'] = $_SERVER['STATECAN_GIT_ROOT'] = $previous;
            }
            (new Filesystem())->remove($path);
        }
    }
}
