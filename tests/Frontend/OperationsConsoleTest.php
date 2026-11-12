<?php

namespace App\Tests\Frontend;

use App\Entity\Agent;
use App\Entity\AgentSession;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\TaskLease;
use App\Entity\User;
use App\Observability\DashboardProjection;
use App\Service\TaskTransitionService;
use Doctrine\ORM\EntityManagerInterface;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Facebook\WebDriver\WebDriverBy;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Panther\PantherTestCase;

#[Group('browser')]
final class OperationsConsoleTest extends PantherTestCase
{
    public function testResponsiveNavigationAndLiveBoardWithRealState(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->setEmail('console-'.bin2hex(random_bytes(6)).'@example.test');
        $user->setPassword(password_hash('console-test-password', PASSWORD_BCRYPT));
        $user->grantOperator();
        $project = new Project('Console browser test');
        $emptyProject = new Project('Empty console project');
        $agent = new Agent('Browser test agent', hash('sha256', random_bytes(32)));
        $session = new AgentSession($agent, 'browser-session');
        foreach ([$user, $project, $emptyProject, $agent, $session] as $entity) {
            $em->persist($entity);
        }
        $tasks = [];
        foreach ([...DashboardProjection::STATES, ...array_fill(0, 20, 'backlog')] as $index => $state) {
            $task = new Task($project, $index.' '.str_repeat('LongUnbrokenTaskTitle', 7));
            if ('backlog' !== $state) {
                $task->applyTransition($state);
            }
            $em->persist($task);
            $tasks[$state] ??= $task;
        }
        $lease = new TaskLease($tasks['running'], $session, hash('sha256', 'browser-lease'), 1, new \DateTimeImmutable('+5 minutes', new \DateTimeZone('UTC')), 'browser-claim');
        $em->persist($lease);
        $em->flush();
        $client = null;
        try {
            $client = self::createPantherClient(['env' => ['PHP_CLI_SERVER_WORKERS' => '4']]);
            $crawler = $client->request('GET', '/login');
            $client->submit($crawler->selectButton('Sign in')->form(['_username' => $user->getEmail(), '_password' => 'console-test-password']));
            $client->request('GET', '/dashboard?project='.$project->getId());
            $client->waitForVisibility('#view-operations');
            self::assertSame('Operations', $client->executeScript('return document.getElementById("view-heading").textContent'));
            self::assertSame('28', $client->executeScript('return document.querySelector(".metrics > div:nth-child(2) dd").textContent'));
            self::assertSame('1', $client->executeScript('return document.querySelector(".metrics > div:first-child dd").textContent'));
            if (!is_dir('var/console-screenshots')) {
                mkdir('var/console-screenshots', 0770, true);
            }
            $client->takeScreenshot('var/console-screenshots/operations-desktop.png');
            $devtools = new ChromeDevToolsDriver($client->getWebDriver());
            foreach ([1440, 1024, 768, 390] as $width) {
                $devtools->execute('Emulation.setDeviceMetricsOverride', ['width' => $width, 'height' => 900, 'deviceScaleFactor' => 1, 'mobile' => false]);
                foreach (['operations', 'work', 'git', 'history', 'runtime'] as $view) {
                    $client->getWebDriver()->findElement(WebDriverBy::cssSelector('.console-nav [data-view-link="'.$view.'"]'))->click();
                    self::assertTrue($client->executeScript('return !document.getElementById(arguments[0]).hidden', ['view-'.$view]));
                    self::assertLessThanOrEqual($width, $client->executeScript('return document.documentElement.scrollWidth'), $view.' overflows at '.$width.'px');
                }
            }
            $devtools->execute('Emulation.setDeviceMetricsOverride', ['width' => 1440, 'height' => 900, 'deviceScaleFactor' => 1, 'mobile' => false]);
            $client->getWebDriver()->findElement(WebDriverBy::cssSelector('.console-nav [data-view-link="work"]'))->click();
            self::assertSame(4, $client->executeScript('return document.querySelectorAll(".board > .column").length'));
            self::assertTrue($client->executeScript('const c=document.querySelector(".column-ready .column-tasks");return c.scrollHeight>c.clientHeight;'));
            self::getContainer()->get(TaskTransitionService::class)->transition($tasks['ready']->getId(), 'blocked', $tasks['ready']->getVersion(), 'browser-test');
            $selector = '.task-card:has(a[href*="'.$tasks['ready']->getId().'"]) .status-blocked';
            $client->waitForVisibility($selector, 10);
            self::assertSame('work', $client->executeScript('return document.querySelector(".console-nav [aria-current]").dataset.viewLink'));
            self::assertSame('work', $client->executeScript('return document.activeElement.dataset.viewLink'));
            $client->takeScreenshot('var/console-screenshots/work-desktop.png');
            $devtools->execute('Network.enable');
            $devtools->execute('Network.emulateNetworkConditions', ['offline' => true, 'latency' => 0, 'downloadThroughput' => 0, 'uploadThroughput' => 0]);
            $client->getWebDriver()->findElement(WebDriverBy::id('refresh-now'))->click();
            $client->waitForVisibility('#console-error');
            self::assertStringContainsString('last snapshot is retained', $client->executeScript('return document.getElementById("console-error").textContent'));
            self::assertSame('work', $client->executeScript('return document.querySelector(".console-nav [aria-current]").dataset.viewLink'));
            $devtools->execute('Network.emulateNetworkConditions', ['offline' => false, 'latency' => 0, 'downloadThroughput' => -1, 'uploadThroughput' => -1]);
            $client->getWebDriver()->findElement(WebDriverBy::id('refresh-now'))->click();
            $client->waitForInvisibility('#console-error');
            $client->request('GET', '/dashboard?project='.$emptyProject->getId().'#work');
            $client->waitForVisibility('#view-work');
            self::assertSame(0, $client->executeScript('return document.querySelectorAll(".task-card").length'));
            self::assertSame(4, $client->executeScript('return document.querySelectorAll(".column-tasks .empty-state").length'));
            $devtools->execute('Emulation.setDeviceMetricsOverride', ['width' => 390, 'height' => 844, 'deviceScaleFactor' => 1, 'mobile' => false]);
            $client->getWebDriver()->findElement(WebDriverBy::cssSelector('.console-nav [data-view-link="operations"]'))->click();
            self::assertLessThanOrEqual(390, $client->executeScript('return document.documentElement.scrollWidth'));
            $client->takeScreenshot('var/console-screenshots/operations-mobile.png');
        } finally {
            $client?->executeScript('window.dispatchEvent(new Event("pagehide"))');
            $db = $em->getConnection();
            $ids = $db->fetchFirstColumn('SELECT id FROM task WHERE project_id = ?', [$project->getId()]);
            foreach ($ids as $id) {
                $db->delete('outbox_event', ['aggregate_id' => $id]);
                $db->delete('state_event', ['task_id' => $id]);
                $db->delete('task_lease', ['task_id' => $id]);
                $db->delete('task', ['id' => $id]);
            }
            $db->delete('agent_session', ['id' => $session->getId()]);
            $db->delete('agent', ['id' => $agent->getId()]);
            $db->delete('project', ['id' => $project->getId()]);
            $db->delete('project', ['id' => $emptyProject->getId()]);
            $db->delete('app_user', ['id' => $user->getId()]);
        }
    }
}
