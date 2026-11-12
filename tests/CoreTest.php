<?php

namespace App\Tests;

use App\Entity\{OutboxEvent, Project, StateEvent, Task, TaskContract};
use App\Service\{TaskService, TaskTransitionService};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CoreTest extends KernelTestCase
{
    public function testTransactionalTaskLifecycle(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $em->getConnection();
        self::assertInstanceOf(\Doctrine\DBAL\Platforms\PostgreSQLPlatform::class, $connection->getDatabasePlatform());
        $connection->beginTransaction();
        $project = new Project('Core integration test');
        $em->persist($project);
        $em->flush();
        $contract = ['schemaVersion' => 1, 'objective' => 'Deliver a feature', 'authorizedScope' => ['src/'], 'constraints' => [], 'dependencies' => [], 'acceptanceCriteria' => ['Works'], 'expectedDeliverables' => ['Code']];
        $tasks = self::getContainer()->get(TaskService::class);
        $task = $tasks->create($project->getId(), 'First title', $contract, 'operator:test');
        $id = $task->getId();
        $task = $tasks->revise($id, 'Final title', $contract + ['resourceBudgets' => ['seconds' => 60]], 1, 'operator:test');
        $task = self::getContainer()->get(TaskTransitionService::class)->transition($id, 'ready', 2, 'operator:test');
        self::assertSame('ready', $task->getStatus());
        self::assertSame(3, $task->getVersion());
        self::assertSame(2, $task->getContractVersion());
        self::assertCount(2, $em->getRepository(TaskContract::class)->findBy(['task' => $task]));
        self::assertCount(3, $em->getRepository(StateEvent::class)->findBy(['task' => $task]));
        self::assertCount(3, $em->getRepository(OutboxEvent::class)->findBy(['aggregateId' => $id]));
        // Verify the caller's enclosing transaction covers task, ledger and durable outbox together.
        $connection->rollBack();
        $em->clear();
        self::assertNull($em->find(Task::class, $id));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM state_event WHERE task_id = ?', [$id]));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM outbox_event WHERE aggregate_id = ?', [$id]));
    }
}
