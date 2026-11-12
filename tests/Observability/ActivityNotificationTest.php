<?php

namespace App\Tests\Observability;

use App\Observability\ActivityNotification;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ActivityNotificationTest extends KernelTestCase
{
    public function testNotificationCarriesAnAuthoritativeRefreshRevision(): void
    {
        $event = (new ActivityNotification())->event(['task' => ['status' => 'running']], null);
        self::assertSame('refresh', $event->getType());
        self::assertTrue(json_decode($event->getData(), true, flags: JSON_THROW_ON_ERROR)['refresh']);
        self::assertSame(3000, $event->getRetry());
    }
}
