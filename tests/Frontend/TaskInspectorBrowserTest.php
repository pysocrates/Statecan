<?php

namespace App\Tests\Frontend;

use App\Entity\{Project, User};
use App\Service\TaskService;
use Doctrine\ORM\EntityManagerInterface;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Facebook\WebDriver\WebDriverBy;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Panther\PantherTestCase;

#[Group('browser')]
final class TaskInspectorBrowserTest extends PantherTestCase
{
    public function testProductionInspectorRetainsContextAndInteractionAcrossLiveUpdates(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $db = $em->getConnection();
        $user = new User();
        $user->setEmail('inspector-browser-'.bin2hex(random_bytes(6)).'@example.test');
        $user->setPassword(password_hash('inspector-browser-password', PASSWORD_BCRYPT));
        $user->grantOperator();
        $project = new Project('Inspector browser fixture');
        $em->persist($user);
        $em->persist($project);
        $em->flush();
        $service = self::getContainer()->get(TaskService::class);
        $contract = ['schemaVersion' => 1, 'objective' => 'Keep inspector context', 'authorizedScope' => ['src/'], 'constraints' => array_fill(0, 8, 'Preserve focused operator context and security'), 'dependencies' => [], 'acceptanceCriteria' => ['Readable evidence'], 'expectedDeliverables' => ['Structured inspector']];
        $task = $service->create($project->getId(), 'Selected inspector task', $contract, 'test');
        $other = $service->create($project->getId(), 'Unrelated task', $contract, 'test');
        $client = null;
        try {
            $driverPort = (int) (getenv('PANTHER_INSPECTOR_DRIVER_PORT') ?: 9591);
            $client = self::createPantherClient(['env' => ['PHP_CLI_SERVER_WORKERS' => '4']], [], ['port' => $driverPort]);
            $crawler = $client->request('GET', '/login');
            $client->submit($crawler->selectButton('Sign in')->form(['_username' => $user->getEmail(), '_password' => 'inspector-browser-password']));
            $client->request('GET', '/dashboard?project='.$project->getId().'&task='.$task->getId().'#work');
            $client->waitForVisibility('#inspector-title');
            self::assertSame('Selected inspector task', $client->executeScript('return document.getElementById("inspector-title").textContent'));
            self::assertSame($task->getId(), $client->executeScript('return document.getElementById("task-inspector").dataset.taskId'));
            $client->getWebDriver()->findElement(WebDriverBy::id('inspector-close'))->click();
            $client->waitForInvisibility('#task-inspector');
            self::assertFalse($client->executeScript('return new URL(location.href).searchParams.has("task")'));
            $link = '.task-card a[href*="'.$task->getId().'"]';
            $client->getWebDriver()->findElement(WebDriverBy::cssSelector($link))->click();
            $client->waitForVisibility('#inspector-title');
            self::assertSame('work', $client->executeScript('return StatecanConsole.view'));
            self::assertSame($task->getId(), $client->executeScript('return new URL(location.href).searchParams.get("task")'));
            // Complete startup/reconnect reconciliation before measuring unrelated invalidations.
            $client->getWebDriver()->executeAsyncScript('const done=arguments[arguments.length-1];document.addEventListener("statecan:inspector-updated",()=>done(true),{once:true});StatecanConsole.refresh();');
            $client->getWebDriver()->findElement(WebDriverBy::id('inspector-section-raw-contract'))->click();
            $client->executeScript('document.getElementById("inspector-section-raw-contract").focus();window.inspectorTitleBefore=document.getElementById("inspector-title");window.inspectorScrollBefore=document.getElementById("task-inspector").scrollTop;');
            $service->revise($other->getId(), 'Unrelated task changed', null, $other->getVersion(), 'test');
            $client->waitFor('.task-card a[title="Unrelated task changed"]', 10);
            self::assertTrue($client->executeScript('return document.getElementById("inspector-title")===window.inspectorTitleBefore'));
            self::assertSame('inspector-section-raw-contract', $client->executeScript('return document.activeElement.id'));
            $service->revise($task->getId(), 'Selected inspector task updated', null, $task->getVersion(), 'test');
            $client->getWebDriver()->wait(10)->until(fn () => $client->executeScript('return document.getElementById("inspector-title").textContent === "Selected inspector task updated"'));
            self::assertTrue($client->executeScript('return document.querySelector("details[data-inspector-section=raw-contract]").open'));
            self::assertSame('inspector-section-raw-contract', $client->executeScript('return document.activeElement.id'));
            self::assertEqualsWithDelta($client->executeScript('return window.inspectorScrollBefore'), $client->executeScript('return document.getElementById("task-inspector").scrollTop'), 3);
            self::assertSame('work', $client->executeScript('return StatecanConsole.view'));
            $devtools = new ChromeDevToolsDriver($client->getWebDriver());
            foreach ([1440, 1024, 768, 390] as $width) {
                $devtools->execute('Emulation.setDeviceMetricsOverride', ['width' => $width, 'height' => 900, 'deviceScaleFactor' => 1, 'mobile' => false]);
                self::assertLessThanOrEqual($width, $client->executeScript('return document.documentElement.scrollWidth'), 'Document overflow with inspector at '.$width);
                self::assertTrue($client->executeScript('const p=document.getElementById("task-inspector");return p.getBoundingClientRect().left>=0 && p.getBoundingClientRect().right<=innerWidth;'));
            }
            if (!is_dir('var/console-screenshots')) { mkdir('var/console-screenshots', 0770, true); }
            $client->takeScreenshot('var/console-screenshots/inspector-mobile.png');
            $client->getWebDriver()->manage()->deleteAllCookies();
            $client->executeScript('StatecanInspector.refresh();');
            $client->waitForVisibility('#inspector-error a');
            self::assertStringContainsString('/login', $client->getWebDriver()->findElement(WebDriverBy::cssSelector('#inspector-error a'))->getAttribute('href'));
            self::assertSame('Selected inspector task updated', $client->executeScript('return document.getElementById("inspector-title").textContent'));
        } finally {
            $client?->executeScript('window.dispatchEvent(new Event("pagehide"))');
            foreach ([$task, $other] as $fixtureTask) {
                $db->delete('outbox_event', ['aggregate_id' => $fixtureTask->getId()]);
                $db->delete('state_event', ['task_id' => $fixtureTask->getId()]);
                $db->delete('task_contract', ['task_id' => $fixtureTask->getId()]);
                $db->delete('task', ['id' => $fixtureTask->getId()]);
            }
            $db->delete('project', ['id' => $project->getId()]);
            $db->delete('app_user', ['id' => $user->getId()]);
        }
    }
}
