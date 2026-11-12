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
use Symfony\Component\Security\Http\Util\TargetPathTrait;

final readonly class AgentAuthenticationResponse implements AuthenticationEntryPointInterface, AuthenticationFailureHandlerInterface
{
    use TargetPathTrait;

    public function __construct(private UrlGeneratorInterface $urls) {}

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        $backgroundRead = str_starts_with($request->getPathInfo(), '/api/v1/')
            || $request->isXmlHttpRequest()
            || str_contains($request->headers->get('Accept', ''), 'text/event-stream');
        if (!$backgroundRead) {
            return new RedirectResponse($this->urls->generate('app_login'));
        }

        // Native EventSource cannot set AJAX headers. Keep background reads out
        // of the main form firewall's saved navigation target as well.
        if ($request->hasSession() && $this->getTargetPath($request->getSession(), 'main') === $request->getUri()) {
            $this->removeTargetPath($request->getSession(), 'main');
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
