<?php

namespace App\Security;

use App\Entity\Agent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

final readonly class AgentTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(private EntityManagerInterface $em) {}

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $agent = $this->em->getRepository(Agent::class)->findOneBy(['tokenHash' => hash('sha256', $accessToken)]);
        if (!$agent) {
            throw new BadCredentialsException('Invalid agent token.');
        }

        return new UserBadge($agent->getId(), static fn () => $agent);
    }
}
