<?php

namespace App\Tests\Frontend;

use App\Entity\Project;
use App\Entity\Task;
use App\Entity\TaskContract;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverKeys;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;

#[Group('browser')]
final class ConsoleAcceptanceTest extends PantherTestCase
{
    private EntityManagerInterface $em;
    private User $operator;
    private Project $project;
    private Project $emptyProject;
    private Task $selectedTask;
    private Task $otherTask;
    private ?Client $browser = null;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->operator = new User();
        $this->operator->setEmail('acceptance-'.bin2hex(random_bytes(8)).'@example.test');
        $this->operator->setPassword(password_hash('browser-test-password', PASSWORD_BCRYPT));
        $this->operator->grantOperator();
        $this->project = new Project('Cross-stage acceptance');
        $this->emptyProject = new Project('Empty acceptance project');
        $this->selectedTask = new Task($this->project, 'Selected task with a versioned contract');
        $this->otherTask = new Task($this->project, 'Unrelated task for live updates');
        $contract = new TaskContract($this->selectedTask, 1, [
            'objective' => 'Inspect authoritative state without losing keyboard position',
            'authorized_scope' => ['console'], 'constraints' => ['read only'],
            'acceptance_criteria' => ['Selected task remains open during unrelated updates'],
            'expected_deliverables' => ['Browser observations'], 'dependencies' => [],
        ]);
        foreach ([$this->operator, $this->project, $this->emptyProject, $this->selectedTask, $this->otherTask, $contract] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }

    public function testResponsiveKeyboardShellAndVisibleConnectionRecovery(): void
    {
        $browser = $this->login();
        $browser->request('GET', '/dashboard?project='.$this->project->getId());
        $browser->waitForVisibility('#view-operations');
        $browser->executeScript('document.querySelector(".skip-link").focus()');
        $browser->getWebDriver()->getKeyboard()->pressKey(WebDriverKeys::ENTER);
        self::assertSame('console-content', $browser->executeScript('return document.activeElement.id'), 'Skip link supports keyboard activation');
        $devtools = new ChromeDevToolsDriver($browser->getWebDriver());
        foreach ([1440, 1024, 768, 390] as $width) {
            $devtools->execute('Emulation.setDeviceMetricsOverride', ['width' => $width, 'height' => 900, 'deviceScaleFactor' => 1, 'mobile' => false]);
            foreach (['operations', 'work', 'git', 'history', 'runtime'] as $view) {
                $this->view($view);
                self::assertLessThanOrEqual($width, $browser->executeScript('return document.documentElement.scrollWidth'), $view.' overflow at '.$width.'px');
                self::assertSame(1, $browser->executeScript('return document.querySelectorAll(".console-nav [aria-current=page]").length'));
                self::assertSame($view, $browser->executeScript('return document.activeElement.dataset.viewLink'));
                self::assertTrue($browser->executeScript('return Array.from(document.querySelectorAll("button:not([hidden]),select,input")).filter(e => e.getClientRects().length).every(e => e.labels?.length || e.getAttribute("aria-label") || e.getAttribute("aria-labelledby") || e.textContent.trim() || ["submit","hidden"].includes(e.type))'), 'Visible form controls require accessible names');
            }
        }
        if (!is_dir('var/console-qa')) {
            mkdir('var/console-qa', 0770, true);
        }
        $browser->takeScreenshot('var/console-qa/console-mobile.png');
        file_put_contents('var/console-qa/shell-observations.json', json_encode($browser->executeScript('return {viewport:innerWidth,documentWidth:document.documentElement.scrollWidth,accountVisible:!!document.querySelector(".account-link")?.getClientRects().length,domNodes:document.querySelectorAll("*").length,navigationBytes:performance.getEntriesByType("navigation")[0]?.transferSize}'), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->view('work');
        $devtools->execute('Network.enable');
        $devtools->execute('Network.emulateNetworkConditions', ['offline' => true, 'latency' => 0, 'downloadThroughput' => 0, 'uploadThroughput' => 0]);
        $browser->getWebDriver()->findElement(WebDriverBy::id('refresh-now'))->click();
        $browser->waitForVisibility('#console-error');
        self::assertStringContainsString('retained', $browser->executeScript('return document.getElementById("console-error").textContent'));
        self::assertSame('work', $browser->executeScript('return document.querySelector(".console-nav [aria-current]").dataset.viewLink'));
        $devtools->execute('Network.emulateNetworkConditions', ['offline' => false, 'latency' => 0, 'downloadThroughput' => -1, 'uploadThroughput' => -1]);
        $browser->getWebDriver()->findElement(WebDriverBy::id('refresh-now'))->click();
        $browser->waitForInvisibility('#console-error');
        $browser->request('GET', '/dashboard?project='.$this->emptyProject->getId().'#work');
        $browser->waitForVisibility('#view-work');
        self::assertSame(0, $browser->executeScript('return document.querySelectorAll(".task-card").length'));
        self::assertSame(4, $browser->executeScript('return document.querySelectorAll(".column-tasks .empty-state").length'));
        // Real session loss: invalidate the browser's cookie, then exercise the normal read path.
        $browser->getWebDriver()->manage()->deleteAllCookies();
        $browser->getWebDriver()->findElement(WebDriverBy::id('refresh-now'))->click();
        $browser->waitForVisibility('#console-error');
        self::assertStringContainsString('session', strtolower($browser->executeScript('return document.getElementById("console-error").textContent')));
        self::assertTrue($browser->executeScript('return document.getElementById("view-work").getClientRects().length > 0'), 'Session expiry retains the last visible state');
    }

    private function login(): Client
    {
        $this->browser = self::createPantherClient(
            ['env' => ['PHP_CLI_SERVER_WORKERS' => '4']],
            managerOptions: ['port' => (int) ($_SERVER['CONSOLE_QA_CHROMEDRIVER_PORT'] ?? 9595)],
        );
        $crawler = $this->browser->request('GET', '/login');
        $this->browser->submit($crawler->selectButton('Sign in')->form(['_username' => $this->operator->getEmail(), '_password' => 'browser-test-password']));

        return $this->browser;
    }

    private function view(string $name): void
    {
        $this->browser->getWebDriver()->findElement(WebDriverBy::cssSelector('.console-nav [data-view-link="'.$name.'"]'))->click();
        $this->browser->waitForVisibility('#view-'.$name);
    }

    protected function tearDown(): void
    {
        try {
            $this->browser?->executeScript('window.dispatchEvent(new Event("pagehide"))');
        } finally {
            if (isset($this->em)) {
                $db = $this->em->getConnection();
                foreach ([$this->selectedTask, $this->otherTask] as $task) {
                    $db->delete('outbox_event', ['aggregate_id' => $task->getId()]);
                    $db->delete('state_event', ['task_id' => $task->getId()]);
                    $db->delete('task_contract', ['task_id' => $task->getId()]);
                    $db->delete('task', ['id' => $task->getId()]);
                }
                $db->delete('project', ['id' => $this->project->getId()]);
                $db->delete('project', ['id' => $this->emptyProject->getId()]);
                $db->delete('app_user', ['id' => $this->operator->getId()]);
            }
            parent::tearDown();
        }
    }
}
