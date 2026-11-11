<?php

namespace App\Tests;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AuthenticationTest extends WebTestCase
{
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
