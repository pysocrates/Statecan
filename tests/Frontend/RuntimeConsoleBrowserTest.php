<?php

namespace App\Tests\Frontend;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Facebook\WebDriver\WebDriverBy;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Panther\PantherTestCase;

#[Group('browser')]
final class RuntimeConsoleBrowserTest extends PantherTestCase
{
    public function testRuntimeFilteringRefreshPreservationAndVisibleFailures(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->setEmail('runtime-browser-'.bin2hex(random_bytes(6)).'@example.test');
        $user->setPassword(password_hash('runtime-browser-password', PASSWORD_BCRYPT));
        $user->grantOperator();
        $em->persist($user);
        $em->flush();
        $db = $em->getConnection();
        $id = 'runtime-browser-'.bin2hex(random_bytes(6));
        $db->executeStatement("INSERT INTO background_job (id, message_type, status, attempts, correlation_id, created_at, error, queue_name) VALUES (?, 'App\\Message\\OutboxMessage', 'retrying', 2, ?, CURRENT_TIMESTAMP, ?, 'receipts')", [$id, $id, 'Temporary evidence storage failure']);
        $client = null;
        try {
            $client = self::createPantherClient(['port' => (int) (getenv('PANTHER_WEB_SERVER_PORT') ?: 9092), 'env' => ['PHP_CLI_SERVER_WORKERS' => '4']], [], ['port' => (int) (getenv('PANTHER_CHROME_DRIVER_PORT') ?: 9522)]);
            $crawler = $client->request('GET', '/login');
            $client->submit($crawler->selectButton('Sign in')->form(['_username' => $user->getEmail(), '_password' => 'runtime-browser-password']));
            // Exercise the feature's real endpoint/assets without modifying coordinator-owned shell.
            $client->request('GET', '/dashboard/runtime');
            $client->executeAsyncScript(<<<'JS'
const done = arguments[arguments.length - 1];
let pending = 3;
const loaded = () => { if (--pending === 0) done(true); };
for (const href of ['/observability.css', '/console-runtime.css']) {
    const link = document.createElement('link'); link.rel = 'stylesheet'; link.href = href; link.onload = loaded; document.head.append(link);
}
const script = document.createElement('script'); script.src = '/console-runtime.js'; script.onload = loaded; document.head.append(script);
JS);
            $client->waitForVisibility('#runtime-panel');
            $devtools = new ChromeDevToolsDriver($client->getWebDriver());
            foreach ([1440, 1024, 768, 390] as $width) {
                $devtools->execute('Emulation.setDeviceMetricsOverride', ['width' => $width, 'height' => 900, 'deviceScaleFactor' => 1, 'mobile' => false]);
                self::assertLessThanOrEqual($width, $client->executeScript('return document.documentElement.scrollWidth'));
                if (in_array($width, [1440, 390], true)) {
                    if (!is_dir('var/console-screenshots')) {
                        mkdir('var/console-screenshots', 0770, true);
                    }
                    $client->takeScreenshot('var/console-screenshots/runtime-'.$width.'.png');
                }
            }
            $client->executeScript('const f=document.getElementById("runtime-job-filter");f.value="retrying";f.dispatchEvent(new Event("change",{bubbles:true}));');
            $detail = '[data-runtime-detail="job-'.$id.'"]';
            $client->getWebDriver()->findElement(WebDriverBy::cssSelector($detail.' summary'))->click();
            self::assertTrue($client->executeScript('return document.querySelector(arguments[0]).open', [$detail]));
            $client->getWebDriver()->findElement(WebDriverBy::id('runtime-refresh'))->click();
            $client->waitForVisibility($detail.'[open]');
            $client->waitForInvisibility('#runtime-panel[aria-busy]');
            self::assertSame('retrying', $client->executeScript('return document.getElementById("runtime-job-filter").value'));
            self::assertSame('runtime-refresh', $client->executeScript('return document.activeElement.id'));
            self::assertStringContainsString('Temporary evidence storage failure', $client->executeScript('return document.querySelector(arguments[0]).textContent', [$detail]));
            $devtools->execute('Network.enable');
            $devtools->execute('Network.emulateNetworkConditions', ['offline' => true, 'latency' => 0, 'downloadThroughput' => 0, 'uploadThroughput' => 0]);
            $client->getWebDriver()->findElement(WebDriverBy::id('runtime-refresh'))->click();
            $client->waitForVisibility('#runtime-error');
            self::assertStringContainsString('Last data is retained', $client->executeScript('return document.getElementById("runtime-error").textContent'));
            self::assertTrue($client->executeScript('return document.querySelector(arguments[0]).open', [$detail]));
            $devtools->execute('Network.emulateNetworkConditions', ['offline' => false, 'latency' => 0, 'downloadThroughput' => -1, 'uploadThroughput' => -1]);
            $client->getWebDriver()->findElement(WebDriverBy::id('runtime-refresh'))->click();
            $client->waitForInvisibility('#runtime-error');
            $client->getWebDriver()->manage()->deleteAllCookies();
            $client->getWebDriver()->findElement(WebDriverBy::id('runtime-refresh'))->click();
            $client->waitForVisibility('#runtime-sign-in');
            self::assertTrue($client->executeScript('return document.getElementById("runtime-refresh").disabled'));
            self::assertTrue($client->executeScript('return document.querySelector(arguments[0]).open', [$detail]));
        } finally {
            $client?->executeScript('window.dispatchEvent(new Event("pagehide"))');
            $db->delete('background_job', ['id' => $id]);
            $db->delete('app_user', ['id' => $user->getId()]);
        }
    }
}
