<?php

namespace App\Tests\Runtime;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RuntimeConsoleTest extends WebTestCase
{
    public function testOperatorReadsStructuredRuntimeAndFailureDetails(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->setEmail('runtime-console-'.bin2hex(random_bytes(6)).'@example.test');
        $user->setPassword('unused-test-password');
        $user->grantOperator();
        $em->persist($user);
        $em->flush();
        $db = $em->getConnection();
        $id = 'runtime-ui-'.bin2hex(random_bytes(8));
        $db->executeStatement("INSERT INTO background_job (id, message_type, status, attempts, correlation_id, created_at, error, queue_name) VALUES (?, 'App\\Message\\OutboxMessage', 'dead_letter', 6, ?, CURRENT_TIMESTAMP, ?, 'receipts')", [$id, $id, '<script>unsafe error</script>']);
        $db->executeStatement("INSERT INTO outbox_event (id, type, aggregate_id, payload, created_at) VALUES (?, 'receipt.requested', ?, '{}', CURRENT_TIMESTAMP)", [$id, $id]);
        try {
            $client->loginUser($user);
            $client->request('GET', '/dashboard/runtime');
            self::assertResponseIsSuccessful();
            self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
            self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('private'));
            self::assertSelectorTextContains('#runtime-title', 'Runtime supervision');
            self::assertSelectorCount(6, '.runtime-queue');
            self::assertSelectorTextContains('[data-runtime-detail="job-'.$id.'"]', 'dead letter');
            self::assertSelectorTextContains('[data-runtime-detail="job-'.$id.'"]', '<script>unsafe error</script>');
            self::assertSelectorNotExists('#runtime-panel script');
            self::assertSelectorTextContains('[data-runtime-detail="outbox-'.$id.'"]', 'Pending dispatch');
            self::assertSelectorTextContains('#runtime-panel', 'not completed by a worker');
            self::assertSelectorExists('#runtime-job-filter');
        } finally {
            $db->delete('background_job', ['id' => $id]);
            $db->delete('outbox_event', ['id' => $id]);
            $db->delete('app_user', ['id' => $user->getId()]);
        }
    }
}
