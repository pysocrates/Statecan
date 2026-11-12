<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final readonly class AgentAuthenticationResponse implements AuthenticationEntryPointInterface, AuthenticationFailureHandlerInterface
{
    public function __construct(private UrlGeneratorInterface $urls) {}

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        if (!str_starts_with($request->getPathInfo(), '/api/v1')) {
            return new RedirectResponse($this->urls->generate('app_login'));
        }

        return $this->unauthorized();
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return $this->unauthorized();
    }

    private function unauthorized(): JsonResponse
    {
        return new JsonResponse(['error' => ['status' => 401, 'code' => 'unauthorized', 'message' => 'Valid authentication credentials are required.']], 401, ['WWW-Authenticate' => 'Bearer']);
    }
}
