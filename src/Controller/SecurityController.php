<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $auth): Response
    {
        if ($this->getUser()) { return $this->redirectToRoute('app_home'); }
        return $this->render('security/login.html.twig', ['last_username' => $auth->getLastUsername(), 'error' => $auth->getLastAuthenticationError()]);
    }

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $hasher): Response
    {
        if ($this->getUser()) { return $this->redirectToRoute('app_home'); }
        $error = null;
        $email = '';
        if ($request->isMethod('POST')) {
            $email = mb_strtolower(trim((string) $request->request->get('email', '')));
            $password = (string) $request->request->get('password', '');
            if (!$this->isCsrfTokenValid('register', (string) $request->request->get('_token', ''))) {
                $error = 'Your session expired. Please try again.';
            } elseif (strlen($email) > 180 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Enter a valid email address.';
            } elseif (strlen($password) < 12 || strlen($password) > 4096) {
                $error = 'Choose a password between 12 and 4096 bytes.';
            } elseif ($em->getRepository(User::class)->findOneBy(['email' => $email])) {
                $error = 'An account with that email already exists.';
            } else {
                $user = new User();
                $user->setEmail($email);
                $user->setPassword($hasher->hashPassword($user, $password));
                $em->persist($user);
                try {
                    $em->flush();
                    $this->addFlash('success', 'Account created. Please sign in.');
                    return $this->redirectToRoute('app_login');
                } catch (UniqueConstraintViolationException) {
                    $error = 'An account with that email already exists.';
                }
            }
        }
        return $this->render('security/register.html.twig', ['email' => $email, 'error' => $error]);
    }

    #[Route('/logout', name: 'app_logout', methods: ['POST'])]
    public function logout(): never { throw new \LogicException('Handled by the security firewall.'); }

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function home(): Response { return $this->render('home/index.html.twig'); }
}
