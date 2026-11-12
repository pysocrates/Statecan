<?php

namespace App\Controller;

use App\Observability\RuntimeConsoleProjection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_OPERATOR')]
final class ConsoleRuntimeController extends AbstractController
{
    #[Route('/dashboard/runtime', name: 'app_console_runtime', methods: ['GET'])]
    public function runtime(RuntimeConsoleProjection $projection): Response
    {
        return $this->render('observability/_runtime.html.twig', ['runtime' => $projection->read()], new Response(headers: ['Cache-Control' => 'private, no-store']));
    }
}
