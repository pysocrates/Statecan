<?php

namespace App\Tests\Frontend;

use App\Entity\GitRepository;
use App\Entity\Project;
use App\Entity\StateEvent;
use App\Entity\Task;
use App\Entity\TaskContract;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverSelect;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Panther\PantherTestCase;
use Symfony\Component\Uid\Uuid;

#[Group('browser')]
final class GitHistoryConsoleTest extends PantherTestCase
{
    public function testInteractiveGraphAndHistoricalReviewPreserveContext(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $db = $em->getConnection();
        $user = new User();
        $user->setEmail('git-browser-'.bin2hex(random_bytes(6)).'@example.test');
        $user->setPassword(password_hash('browser-review-password', PASSWORD_BCRYPT));
        $user->grantOperator();
        $project = new Project('Provenance browser fixture');
        $task = new Task($project, 'Review indexed work');
        $repository = new GitRepository(Uuid::v7()->toRfc4122(), $project, 'fixture:'.bin2hex(random_bytes(8)));
        foreach ([$user, $project, $task, $repository, new TaskContract($task, 1, ['objective' => 'Keep historical requirements', 'schemaVersion' => 1, 'authorizedScope' => ['src/'], 'constraints' => [], 'dependencies' => [], 'expectedDeliverables' => ['Indexed evidence'], 'acceptanceCriteria' => ['Trace indexed work']])] as $entity) {
            $em->persist($entity);
        }
        for ($i = 1; $i <= 26; ++$i) {
            $em->persist(new StateEvent($task, 'backlog', 'ready', $i, 'browser-review'));
        }
        $em->flush();
        $sha = str_repeat('a', 40);
        $parent = str_repeat('b', 40);
        $at = gmdate('Y-m-d H:i:s');
        $db->executeStatement('UPDATE git_repository SET refs = ?, observed_at = ? WHERE id = ?', [json_encode(['refs/heads/main' => $sha]), $at, $repository->getId()]);
        foreach ([$sha, $parent] as $commit) {
            $db->insert('git_event', ['id' => Uuid::v7()->toRfc4122(), 'repository_id' => $repository->getId(), 'event_key' => 'commit:'.$commit, 'kind' => 'commit', 'sha' => $commit, 'observed_at' => $at, 'metadata' => json_encode(['message' => $commit === $sha ? 'Trace indexed work' : 'Earlier indexed work', 'author' => 'Recorded author', 'parents' => $commit === $sha ? [$parent] : [], 'files' => ['src/Example.php']])]);
        }
        $client = null;
        try {
            $client = self::createPantherClient(['env' => ['PHP_CLI_SERVER_WORKERS' => '4']], [], ['port' => (int) (getenv('PANTHER_CHROMEDRIVER_PORT') ?: 9515)]);
            $crawler = $client->request('GET', '/login');
            $client->submit($crawler->selectButton('Sign in')->form(['_username' => $user->getEmail(), '_password' => 'browser-review-password']));
            $client->waitForVisibility('form[action$="/logout"]');
            if (!is_dir('var/console-screenshots')) {
                mkdir('var/console-screenshots', 0770, true);
            }
            $client->request('GET', '/dashboard?project='.$project->getId());
            $client->getWebDriver()->findElement(WebDriverBy::cssSelector('.console-nav [data-view-link="git"]'))->click();
            $client->waitForVisibility('#git-panel [data-git-graph]');
            $box = $client->executeScript('return document.querySelector("[data-git-graph]").getAttribute("viewBox")');
            $client->getWebDriver()->findElement(WebDriverBy::cssSelector('[data-graph-zoom="in"]'))->click();
            self::assertNotSame($box, $client->executeScript('return document.querySelector("[data-git-graph]").getAttribute("viewBox")'));
            $client->getWebDriver()->findElement(WebDriverBy::cssSelector('[data-graph-fit]'))->click();
            self::assertSame($box, $client->executeScript('return document.querySelector("[data-git-graph]").getAttribute("viewBox")'));
            (new WebDriverSelect($client->getWebDriver()->findElement(WebDriverBy::id('git-branch'))))->selectByValue('refs/heads/main');
            $client->getWebDriver()->findElement(WebDriverBy::id('git-search'))->sendKeys('Trace');
            $client->getWebDriver()->findElement(WebDriverBy::cssSelector('#git-panel [data-review-form] button'))->click();
            $client->wait(10)->until(static fn () => 1 === $client->executeScript('return document.querySelectorAll("[data-git-commit]").length'));
            $client->getWebDriver()->findElement(WebDriverBy::cssSelector('[data-git-commit="'.$sha.'"]'))->click();
            $client->waitForVisibility('[data-git-selected-content] .git-commit-detail');
            self::assertStringContainsString('Trace indexed work', $client->executeScript('return document.querySelector("[data-git-selected-content]").textContent'));
            $client->getWebDriver()->findElement(WebDriverBy::cssSelector('[data-git-close]'))->click();
            self::assertSame($sha, $client->executeScript('return document.activeElement.dataset.gitCommit'));
            $client->getWebDriver()->findElement(WebDriverBy::cssSelector('[data-git-commit="'.$sha.'"]'))->click();
            $client->waitForVisibility('[data-git-selected-content] .git-commit-detail');
            $client->executeScript('const d=document.querySelector(".git-timeline details");d.open=true;window.reviewOpenKey=d.dataset.reviewDetail;document.getElementById("git-search").focus();window.reviewNode=document.getElementById("git-panel");window.reviewUpdated=false;document.addEventListener("statecan:review-updated",()=>window.reviewUpdated=true,{once:true});window.StatecanConsole.invalidate({components:["git"],entities:[],reconcile:false});');
            $client->wait(10)->until(static fn () => $client->executeScript('return window.reviewUpdated === true && !document.getElementById("git-panel").hasAttribute("aria-busy")'));
            self::assertTrue($client->executeScript('return window.reviewNode === document.getElementById("git-panel")'));
            self::assertTrue($client->executeScript('return [...document.querySelectorAll(".git-timeline details[open]")].some(d=>d.dataset.reviewDetail===window.reviewOpenKey)'));
            self::assertSame('git-search', $client->executeScript('return document.activeElement.id'));
            $client->takeScreenshot('var/console-screenshots/git-desktop.png');
            $client->getWebDriver()->findElement(WebDriverBy::cssSelector('.console-nav [data-view-link="history"]'))->click();
            $client->waitForVisibility('#history-kind');
            self::assertSame(25, $client->executeScript('return document.querySelectorAll(".history-records>li").length'));
            $client->getWebDriver()->findElement(WebDriverBy::cssSelector('#history-panel [data-review-page="1"]'))->click();
            $client->wait(10)->until(static fn () => 1 === $client->executeScript('return document.querySelectorAll(".history-records>li").length'));
            (new WebDriverSelect($client->getWebDriver()->findElement(WebDriverBy::id('history-kind'))))->selectByValue('contracts');
            $client->waitForVisibility('.history-records [data-entity-type="task_contract"]');
            $client->getWebDriver()->findElement(WebDriverBy::cssSelector('.history-records>li>details>summary'))->click();
            self::assertStringContainsString('Keep historical requirements', $client->executeScript('return document.querySelector(".history-records").textContent'));
            $devtools = new ChromeDevToolsDriver($client->getWebDriver());
            foreach ([1440, 1024, 768, 390] as $width) {
                $devtools->execute('Emulation.setDeviceMetricsOverride', ['width' => $width, 'height' => 900, 'deviceScaleFactor' => 1, 'mobile' => false]);
                foreach (['git', 'history'] as $view) {
                    $client->getWebDriver()->findElement(WebDriverBy::cssSelector('.console-nav [data-view-link="'.$view.'"]'))->click();
                    self::assertLessThanOrEqual($width, $client->executeScript('return document.documentElement.scrollWidth'), $view.' at '.$width);
                    if (390 === $width && 'git' === $view) {
                        $client->takeScreenshot('var/console-screenshots/git-mobile.png');
                    }
                }
            }
            if (!is_dir('var/console-screenshots')) {
                mkdir('var/console-screenshots', 0770, true);
            }
            $client->takeScreenshot('var/console-screenshots/history-mobile.png');
        } finally {
            $client?->executeScript('window.dispatchEvent(new Event("pagehide"))');
            foreach (['git_task_association', 'git_event'] as $table) {
                $db->delete($table, ['repository_id' => $repository->getId()]);
            }
            $db->delete('git_repository', ['id' => $repository->getId()]);
            foreach (['task_contract', 'state_event'] as $table) {
                $db->delete($table, ['task_id' => $task->getId()]);
            }
            $db->delete('task', ['id' => $task->getId()]);
            $db->delete('project', ['id' => $project->getId()]);
            $db->delete('app_user', ['id' => $user->getId()]);
        }
    }
}
