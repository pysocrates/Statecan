<?php

namespace App\Tests\Frontend;

use App\Entity\Agent;
use App\Entity\AgentSession;
use App\Entity\GitRepository;
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
use Symfony\Component\Uid\Uuid;

#[Group('browser')]
final class ConsoleAcceptanceTest extends PantherTestCase
{
    private EntityManagerInterface $em;
    private User $operator;
    private Project $project;
    private Project $emptyProject;
    private Task $selectedTask;
    private Task $otherTask;
    private Agent $agent;
    private AgentSession $session;
    private ?Client $browser = null;
    private ?GitRepository $repository = null;
    private ?string $jobId = null;

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
        $this->agent = new Agent('QA idle session', hash('sha256', random_bytes(32)));
        $this->session = new AgentSession($this->agent, 'qa-session');
        $contract = new TaskContract($this->selectedTask, 1, [
            'objective' => 'Inspect authoritative state without losing keyboard position',
            'authorizedScope' => ['console'], 'constraints' => ['read only'],
            'acceptanceCriteria' => ['Selected task remains open during unrelated updates'],
            'expectedDeliverables' => ['Browser observations'], 'dependencies' => [],
        ]);
        foreach ([$this->operator, $this->project, $this->emptyProject, $this->selectedTask, $this->otherTask, $contract, $this->agent, $this->session] as $entity) {
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
        self::assertTrue($browser->executeScript('return document.getElementById("console-account").getClientRects().length > 0'), 'Account remains reachable on mobile');
        $browser->takeScreenshot('var/console-qa/console-mobile.png');
        file_put_contents('var/console-qa/shell-observations.json', json_encode($browser->executeScript('return {viewport:innerWidth,documentWidth:document.documentElement.scrollWidth,accountVisible:[...document.querySelectorAll("#console-account,.account-link")].some(e=>e.getClientRects().length),domNodes:document.querySelectorAll("*").length,navigationBytes:performance.getEntriesByType("navigation")[0]?.transferSize}'), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
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
        $browser->request('GET', '/dashboard?project='.$this->emptyProject->getId());
        $this->view('work');
        $browser->waitForVisibility('#view-work');
        self::assertSame(0, $browser->executeScript('return document.querySelectorAll(".task-card").length'));
        self::assertSame(4, $browser->executeScript('return document.querySelectorAll(".column-tasks .empty-state").length'));
        // Real session loss: invalidate the browser's cookie, then exercise the normal read path.
        $browser->getWebDriver()->manage()->deleteAllCookies();
        $browser->getWebDriver()->findElement(WebDriverBy::id('refresh-now'))->click();
        $browser->waitForVisibility('#console-error');
        self::assertStringContainsString('session', strtolower($browser->executeScript('return document.getElementById("console-error").textContent')));
        self::assertTrue($browser->executeScript('return document.getElementById("view-work").getClientRects().length > 0'), 'Session expiry retains the last visible state');
        self::assertTrue($browser->executeScript('return !!document.querySelector(arguments[0])?.getClientRects().length', ['#console-error a[href]']), 'Expired-session notice includes a keyboard-reachable recovery link');
        $browser->getWebDriver()->findElement(WebDriverBy::cssSelector('#console-error a[href]'))->click();
        $browser->waitForVisibility('input[name="_username"]');
        $crawler = $browser->refreshCrawler();
        $browser->submit($crawler->selectButton('Sign in')->form(['_username' => $this->operator->getEmail(), '_password' => 'browser-test-password']));
        $browser->waitForVisibility('form[action$="/logout"]');
        self::assertStringContainsString($this->operator->getEmail(), $browser->executeScript('return document.querySelector("main").textContent'), 'Sign-in recovery returns an authenticated HTML page, not background JSON');
    }

    public function testContextualInspectorAndDebouncedUpdatesPreserveUserState(): void
    {
        $browser = $this->login();
        $browser->request('GET', '/dashboard?project='.$this->project->getId());
        $this->view('work');
        $browser->waitForVisibility('#view-work');
        $browser->executeScript(<<<'JS'
window.__qaRequests = [];
window.__qaLastRead = performance.now();
window.__qaPageIdentity = {};
const originalFetch = window.fetch;
window.fetch = function (...args) {
    const path = new URL(args[0] instanceof Request ? args[0].url : String(args[0]), location.href).pathname;
    window.__qaRequests.push(path);
    window.__qaLastRead = performance.now();
    return originalFetch.apply(this, args);
};
JS);
        $browser->getWebDriver()->findElement(WebDriverBy::cssSelector('.task-card a[href*="'.$this->selectedTask->getId().'"]'))->click();
        $browser->waitForVisibility('#inspector-title');
        $this->waitForReadsToSettle();
        self::assertSame('work', $browser->executeScript('return document.querySelector(".console-nav [aria-current]").dataset.viewLink'));
        self::assertSame($this->selectedTask->getId(), $browser->executeScript('return new URL(location.href).searchParams.get("task")'));
        self::assertTrue($browser->executeScript('return !!window.__qaPageIdentity'), 'Inspector opens without a document navigation');
        $browser->executeScript(<<<'JS'
const section = document.querySelector('[data-inspector-section="contract"]');
section.open = true;
document.getElementById('inspector-section-contract').focus();
window.__qaSelectedSection = section;
window.__qaRequests = [];
JS);
        $this->session->heartbeat();
        $this->em->flush();
        $browser->executeScript('window.__qaLastRead = performance.now()');
        $this->waitForReadsToSettle();
        self::assertSame(0, $browser->executeScript('return window.__qaRequests.filter(path => path.includes("/dashboard/components/work") || path.includes("/dashboard/fragment") || path.includes("/observability/snapshot")).length'), 'Unrelated session heartbeat does not fetch Work or full snapshots');
        self::getContainer()->get(\App\Service\TaskTransitionService::class)->transition($this->otherTask->getId(), 'ready', 1, 'browser-acceptance');
        $browser->getWebDriver()->wait(10)->until(fn (): bool => $browser->executeScript('return !!document.querySelector(arguments[0])?.closest(".task-card")?.querySelector(".status-ready")', ['.task-card a[href*="'.$this->otherTask->getId().'"]']));
        $this->waitForReadsToSettle();
        self::assertTrue($browser->executeScript('return document.querySelector(\'[data-inspector-section="contract"]\') === window.__qaSelectedSection'), 'Unrelated task updates preserve inspector DOM identity');
        self::assertSame('inspector-section-contract', $browser->executeScript('return document.activeElement.id'));
        self::assertTrue($browser->executeScript('return document.querySelector(\'[data-inspector-section="contract"]\').open'));
        self::assertSame(0, $browser->executeScript('return window.__qaRequests.filter(path => path.includes("/tasks/" + arguments[0] + "/inspector")).length', [$this->selectedTask->getId()]), 'Unrelated task changes do not refetch selected task details');

        self::getContainer()->get(\App\Service\TaskTransitionService::class)->transition($this->selectedTask->getId(), 'ready', 1, 'browser-acceptance');
        $browser->waitForVisibility('#inspector-content .status-ready', 10);
        self::assertSame('inspector-section-contract', $browser->executeScript('return document.activeElement.id'));
        self::assertTrue($browser->executeScript('return document.querySelector(\'[data-inspector-section="contract"]\').open'), 'Selected-task reconciliation retains expansion');
        $this->waitForReadsToSettle();
        $browser->executeScript(<<<'JS'
window.__qaRequests = [];
window.__qaNodesBefore = document.querySelectorAll('*').length;
window.__qaLastRead = performance.now();
for (let i = 0; i < 25; ++i) {
    window.StatecanConsole.invalidate({components: ['work'], entities: [], reconcile: false});
}
JS);
        $this->waitForReadsToSettle();
        $requests = $browser->executeScript('return window.__qaRequests.filter(path => path.includes("/dashboard/components/work")).length');
        self::assertGreaterThanOrEqual(1, $requests, 'Burst causes an authoritative read');
        self::assertLessThanOrEqual(2, $requests, 'Burst is coalesced instead of one request per event');
        self::assertLessThanOrEqual(5, $browser->executeScript('return document.querySelectorAll("*").length - window.__qaNodesBefore'), 'Reconciliation does not append duplicate component trees');
        self::assertSame($this->selectedTask->getId(), $browser->executeScript('return new URL(location.href).searchParams.get("task")'));
        $devtools = new ChromeDevToolsDriver($browser->getWebDriver());
        foreach ([1440, 1024, 768, 390] as $width) {
            $devtools->execute('Emulation.setDeviceMetricsOverride', ['width' => $width, 'height' => 900, 'deviceScaleFactor' => 1, 'mobile' => false]);
            self::assertLessThanOrEqual($width, $browser->executeScript('return document.documentElement.scrollWidth'), 'Open inspector overflows at '.$width);
            self::assertTrue($browser->executeScript('return document.getElementById("inspector-close").getClientRects().length > 0'), 'Close control remains reachable');
        }
        $browser->takeScreenshot('var/console-qa/inspector-mobile.png');
        $browser->getWebDriver()->findElement(WebDriverBy::id('inspector-close'))->click();
        $browser->waitForInvisibility('#task-inspector');
        self::assertNull($browser->executeScript('return new URL(location.href).searchParams.get("task")'));
        self::assertTrue($browser->executeScript('return document.activeElement !== document.body && document.activeElement.getClientRects().length > 0'), 'Closing inspector returns focus to visible workspace content');
    }

    public function testBoundedGitHistoryAndRuntimeInvestigationControls(): void
    {
        $this->seedIndexedReviewFixture();
        $browser = $this->login();
        $browser->request('GET', '/dashboard?project='.$this->project->getId());
        $this->view('git');
        $browser->waitForVisibility('#git-panel[data-review-loaded="true"]');
        self::assertSame(25, $browser->executeScript('return document.querySelectorAll(".git-timeline > li[data-entity-id]").length'));
        self::assertLessThanOrEqual(50, $browser->executeScript('return document.querySelectorAll("[data-git-graph] [data-git-commit]").length'));
        $initialBox = $browser->executeScript('return document.querySelector("[data-git-graph]").getAttribute("viewBox")');
        $browser->getWebDriver()->findElement(WebDriverBy::cssSelector('[data-graph-zoom="in"]'))->click();
        self::assertNotSame($initialBox, $browser->executeScript('return document.querySelector("[data-git-graph]").getAttribute("viewBox")'));
        $browser->getWebDriver()->findElement(WebDriverBy::cssSelector('[data-graph-fit]'))->click();
        self::assertSame($initialBox, $browser->executeScript('return document.querySelector("[data-git-graph]").getAttribute("viewBox")'));
        $browser->getWebDriver()->findElement(WebDriverBy::id('git-search'))->sendKeys('Fixture commit 31');
        $browser->getWebDriver()->findElement(WebDriverBy::cssSelector('#git-panel [data-review-form] button'))->click();
        $browser->getWebDriver()->wait(10)->until(fn (): bool => 1 === $browser->executeScript('return document.querySelectorAll(".git-timeline > li[data-entity-id]").length'));
        self::assertSame('Fixture commit 31', $browser->executeScript('return document.getElementById("git-search").value'));

        $this->view('history');
        $browser->waitForVisibility('#history-panel[data-review-loaded="true"]');
        self::assertSame(25, $browser->executeScript('return document.querySelectorAll(".history-records > li[data-entity-id]").length'));
        $browser->getWebDriver()->findElement(WebDriverBy::cssSelector('#history-panel [data-review-page="1"]'))->click();
        $browser->getWebDriver()->wait(10)->until(fn (): bool => 7 === $browser->executeScript('return document.querySelectorAll(".history-records > li[data-entity-id]").length'));
        $browser->getWebDriver()->findElement(WebDriverBy::id('history-search'))->sendKeys('no matching fixture');
        $browser->getWebDriver()->findElement(WebDriverBy::cssSelector('#history-panel [data-review-form] button'))->click();
        $browser->getWebDriver()->wait(10)->until(fn (): bool => 0 === $browser->executeScript('return document.querySelectorAll(".history-records > li[data-entity-id]").length'));
        self::assertStringContainsString('No', $browser->executeScript('return document.querySelector(".history-records").textContent'));

        $this->view('runtime');
        $browser->waitForVisibility('#runtime-panel[data-runtime-loaded="true"]:not([aria-busy="true"])');
        $browser->executeScript('window.__qaRuntimeUpdates=0;document.addEventListener("statecan:component-updated",event=>{if(event.detail.component==="runtime")window.__qaRuntimeUpdates++})');
        $browser->getWebDriver()->findElement(WebDriverBy::id('runtime-refresh'))->click();
        $browser->getWebDriver()->wait(10)->until(fn (): bool => $browser->executeScript('return window.__qaRuntimeUpdates > 0 && !document.getElementById("runtime-panel").hasAttribute("aria-busy")'));
        $selector = '[data-runtime-detail="job-'.$this->jobId.'"]';
        $browser->waitForVisibility($selector);
        $browser->getWebDriver()->findElement(WebDriverBy::cssSelector($selector.' > summary'))->click();
        $browser->executeScript('document.querySelector(arguments[0]).focus()', [$selector.' > summary']);
        $browser->executeScript('window.__qaRuntimeUpdates=0;window.StatecanConsole.invalidate({components:["runtime"],entities:[],reconcile:false})');
        $browser->getWebDriver()->wait(10)->until(fn (): bool => $browser->executeScript('return window.__qaRuntimeUpdates > 0 && !document.getElementById("runtime-panel").hasAttribute("aria-busy")'));
        self::assertTrue($browser->executeScript('return document.querySelector(arguments[0]).open', [$selector]));
        self::assertSame('job-'.$this->jobId, $browser->executeScript('return document.activeElement.closest("[data-runtime-detail]")?.dataset.runtimeDetail'));
        self::assertStringContainsString('Recorded QA fixture failure', $browser->executeScript('return document.querySelector(arguments[0]).textContent', [$selector]));
        $devtools = new ChromeDevToolsDriver($browser->getWebDriver());
        foreach ([1440, 1024, 768, 390] as $width) {
            $devtools->execute('Emulation.setDeviceMetricsOverride', ['width' => $width, 'height' => 900, 'deviceScaleFactor' => 1, 'mobile' => false]);
            foreach (['git', 'history', 'runtime'] as $view) {
                $this->view($view);
                self::assertLessThanOrEqual($width, $browser->executeScript('return document.documentElement.scrollWidth'), 'Populated '.$view.' overflows at '.$width);
            }
        }
    }

    private function seedIndexedReviewFixture(): void
    {
        $service = self::getContainer()->get(\App\Service\TaskTransitionService::class);
        for ($i = 0; $i < 32; ++$i) {
            $service->transition($this->otherTask->getId(), 0 === $i % 2 ? 'ready' : 'blocked', $i + 1, 'qa-recorded-transition');
        }
        $this->repository = new GitRepository(Uuid::v7()->toRfc4122(), $this->project, 'qa-observation-'.bin2hex(random_bytes(8)));
        $this->em->persist($this->repository);
        $this->em->flush();
        $db = $this->em->getConnection();
        $parent = null;
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        for ($i = 0; $i < 32; ++$i) {
            $sha = hash('sha1', $this->repository->getId().'-'.$i);
            $db->insert('git_event', [
                'id' => Uuid::v7()->toRfc4122(), 'repository_id' => $this->repository->getId(),
                'event_key' => 'commit:'.$sha, 'kind' => 'commit', 'sha' => $sha,
                'metadata' => json_encode(['parents' => $parent ? [$parent] : [], 'files' => ['example.php'], 'message' => 'Fixture commit '.$i, 'author' => 'Observed fixture identity', 'authoredAt' => $now->format(DATE_ATOM)], JSON_THROW_ON_ERROR),
                'observed_at' => $now->format('Y-m-d H:i:s'),
            ]);
            $parent = $sha;
        }
        $db->update('git_repository', ['refs' => json_encode(['refs/heads/fixture' => $parent], JSON_THROW_ON_ERROR), 'observed_at' => $now->format('Y-m-d H:i:s')], ['id' => $this->repository->getId()]);
        $this->jobId = 'qa-'.Uuid::v7()->toRfc4122();
        $db->insert('background_job', ['id' => $this->jobId, 'message_type' => 'QAObservedJob', 'status' => 'failed', 'attempts' => 2, 'correlation_id' => $this->jobId, 'created_at' => $now->format('Y-m-d H:i:sP'), 'error' => 'Recorded QA fixture failure', 'queue_name' => 'receipts']);
    }

    private function waitForReadsToSettle(): void
    {
        $this->browser->getWebDriver()->wait(10)->until(fn (): bool => $this->browser->executeScript('return performance.now() - window.__qaLastRead > 600 && !document.querySelector(\'[aria-busy="true"]\')'));
    }

    private function login(): Client
    {
        $this->browser = self::createPantherClient(
            ['env' => ['PHP_CLI_SERVER_WORKERS' => '4', 'STATECAN_SSE_DURATION' => '25']],
            managerOptions: ['port' => (int) ($_SERVER['CONSOLE_QA_CHROMEDRIVER_PORT'] ?? 9595)],
        );
        $this->browser->getWebDriver()->manage()->deleteAllCookies();
        $crawler = $this->browser->request('GET', '/login');
        $this->browser->submit($crawler->selectButton('Sign in')->form(['_username' => $this->operator->getEmail(), '_password' => 'browser-test-password']));
        $this->browser->waitForVisibility('form[action$="/logout"]');

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
                if ($this->repository) {
                    $db->delete('git_event', ['repository_id' => $this->repository->getId()]);
                    $db->delete('git_repository', ['id' => $this->repository->getId()]);
                }
                if ($this->jobId) {
                    $db->delete('background_job', ['id' => $this->jobId]);
                }
                foreach ([$this->selectedTask, $this->otherTask] as $task) {
                    $db->delete('outbox_event', ['aggregate_id' => $task->getId()]);
                    $db->delete('state_event', ['task_id' => $task->getId()]);
                    $db->delete('task_contract', ['task_id' => $task->getId()]);
                    $db->delete('task', ['id' => $task->getId()]);
                }
                $db->delete('agent_session', ['id' => $this->session->getId()]);
                $db->delete('agent', ['id' => $this->agent->getId()]);
                $db->delete('project', ['id' => $this->project->getId()]);
                $db->delete('project', ['id' => $this->emptyProject->getId()]);
                $db->delete('app_user', ['id' => $this->operator->getId()]);
            }
            parent::tearDown();
        }
    }
}
