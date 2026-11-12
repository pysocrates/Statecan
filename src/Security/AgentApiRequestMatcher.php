<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;

final class AgentApiRequestMatcher implements RequestMatcherInterface
{
    public function matches(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), '/api/v1') && $request->headers->has('Authorization');
    }
}
