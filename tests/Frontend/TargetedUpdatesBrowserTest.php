<?php

namespace App\Tests\Frontend;

use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use App\Service\TaskTransitionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Panther\PantherTestCase;

#[Group('browser')]
final class TargetedUpdatesBrowserTest extends PantherTestCase
{
    public function testLiveScopedRefreshRetainsStableNodesAndCoalescesBursts(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->setEmail('targeted-browser-'.bin2hex(random_bytes(6)).'@example.invalid');
        $user->setPassword(password_hash('targeted-browser-password', PASSWORD_BCRYPT));
        $user->grantOperator();
        $project = new Project('Targeted browser smoke');
        $stable = new Task($project, 'Stable focused task');
        $changed = new Task($project, 'Changing task');
        $stable->applyTransition('ready');
        $changed->applyTransition('ready');
        $browser = null;
        try {
            foreach ([$user, $project, $stable, $changed] as $entity) {
                $em->persist($entity);
            }
            $em->flush();
            $browser = self::createPantherClient(['env' => ['PHP_CLI_SERVER_WORKERS' => '4']], [], ['port' => (int) ($_SERVER['PANTHER_CHROME_DRIVER_PORT'] ?? 9515)]);
            $crawler = $browser->request('GET', '/login');
            $browser->submit($crawler->selectButton('Sign in')->form(['_username' => $user->getEmail(), '_password' => 'targeted-browser-password']));
            $browser->request('GET', '/dashboard?project='.$project->getId().'#work');
            $browser->getWebDriver()->wait(10)->until(fn (): bool => $browser->executeScript('return document.getElementById("freshness").textContent.startsWith("Work updated")'));
            $browser->executeScript(<<<'JS'
window.__reads = [];
const fetchOriginal = window.fetch;
window.fetch = (...args) => { window.__reads.push(String(args[0])); return fetchOriginal(...args); };
window.__work = document.querySelector('[data-console-component="work"]');
window.__link = document.querySelector('a[href*="' + arguments[0] + '"]');
window.__link.focus();
window.__updates = 0;
document.addEventListener('statecan:component-updated', event => { if (event.detail.component === 'work') window.__updates++; });
JS, [$stable->getId()]);
            self::getContainer()->get(TaskTransitionService::class)->transition($changed->getId(), 'blocked', $changed->getVersion(), 'targeted-browser');
            $browser->waitForVisibility('.task-card:has(a[href*="'.$changed->getId().'"]) .status-blocked', 10);
            self::assertTrue($browser->executeScript('return window.__work === document.querySelector(\'[data-console-component="work"]\')'));
            self::assertTrue($browser->executeScript('return window.__link.isConnected && document.activeElement === window.__link'));
            self::assertSame('work', $browser->executeScript('return window.StatecanConsole.view'));
            self::assertSame(0, $browser->executeScript('return window.__reads.filter(url => url.includes("/dashboard/fragment") || url.includes("/observability/snapshot")).length'));
            $browser->executeScript('window.__reads=[];window.__updates=0;for(let i=0;i<25;i++)window.StatecanConsole.invalidate({components:["work"],entities:[],reconcile:false});');
            $browser->getWebDriver()->wait(10)->until(fn (): bool => $browser->executeScript('return window.__updates >= 1'));
            $browser->executeAsyncScript('const done=arguments[arguments.length-1];setTimeout(done,700);');
            $reads = $browser->executeScript('return window.__reads.filter(url => url.includes("/dashboard/components/work")).length');
            self::assertGreaterThanOrEqual(1, $reads);
            self::assertLessThanOrEqual(2, $reads);
            self::assertSame(1, $browser->executeScript('return document.querySelectorAll(\'[data-console-component="work"]\').length'));
            self::assertTrue($browser->executeScript('return window.__link.isConnected && document.activeElement === window.__link'));
        } finally {
            $browser?->executeScript('window.dispatchEvent(new Event("pagehide"))');
            $db = $em->getConnection();
            foreach ([$stable, $changed] as $task) {
                $db->delete('outbox_event', ['aggregate_id' => $task->getId()]);
                $db->delete('state_event', ['task_id' => $task->getId()]);
                $db->delete('task', ['id' => $task->getId()]);
            }
            $db->delete('project', ['id' => $project->getId()]);
            if (null !== $user->getId()) {
                $db->delete('app_user', ['id' => $user->getId()]);
            }
        }
    }
}
