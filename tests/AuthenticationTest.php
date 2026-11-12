<?php

namespace App\Tests;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AuthenticationTest extends WebTestCase
{
    public function testOnlyFirstRegisteredAccountBecomesOperator(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $connection = $em->getConnection();
        $connection->beginTransaction();

        try {
            // Temporarily start with no accounts; rollback restores existing test fixtures.
            $connection->executeStatement('DELETE FROM app_user');
            foreach (['first@example.test', 'second@example.test'] as $email) {
                $crawler = $client->request('GET', '/register');
                $client->submit($crawler->selectButton('Create account')->form([
                    'email' => $email,
                    'password' => 'long-test-password',
                ]));
                self::assertResponseRedirects('/login');
            }

            $first = $em->getRepository(User::class)->findOneBy(['email' => 'first@example.test']);
            $second = $em->getRepository(User::class)->findOneBy(['email' => 'second@example.test']);
            self::assertContains('ROLE_OPERATOR', $first->getRoles());
            self::assertNotContains('ROLE_OPERATOR', $second->getRoles());

            $crawler = $client->request('GET', '/login');
            $client->submit($crawler->selectButton('Sign in')->form([
                '_username' => 'first@example.test',
                '_password' => 'long-test-password',
            ]));
            self::assertResponseRedirects();
            $client->followRedirect();
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('a[href="/dashboard"]');
        } finally {
            $connection->rollBack();
            $em->clear();
        }
    }

    public function testRegistrationLoginAndLogout(): void
    {
        $client = static::createClient();
        $email = 'test-'.bin2hex(random_bytes(8)).'@example.com';
        $client->request('GET', '/');
        self::assertResponseRedirects('/login');
        $crawler = $client->request('GET', '/register');
        $client->submit($crawler->selectButton('Create account')->form(['email' => $email, 'password' => 'long-test-password']));
        self::assertResponseRedirects('/login');
        $crawler = $client->followRedirect();
        $client->submit($crawler->selectButton('Sign in')->form(['_username' => $email, '_password' => 'wrong-password']));
        self::assertResponseRedirects('/login');
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'Invalid credentials');
        $client->submit($crawler->selectButton('Sign in')->form(['_username' => $email, '_password' => 'long-test-password']));
        self::assertResponseRedirects();
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('main', $email);
        $client->submit($crawler->selectButton('Sign out')->form());
        self::assertResponseRedirects('/login');
        $client->request('GET', '/');
        self::assertResponseRedirects('/login');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        $em->remove($user);
        $em->flush();
    }
}
