<?php

namespace App\Agent;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class LeaseInput
{
    public function __construct(
        #[Assert\NotBlank, Assert\Length(max: 36)] public string $sessionId,
        #[Assert\Length(min: 32, max: 128)] public string $token,
        #[Assert\Positive] public int $expectedVersion,
        #[Assert\PositiveOrZero] public int $generation = 0,
        #[Assert\Choice(choices: ['running', 'blocked', 'awaiting_review', 'failed', 'ready'])] public ?string $target = null,
        #[Assert\Length(max: 100000)] public string $result = '',
        #[Assert\Count(max: 100)] #[Assert\All(constraints: [new Assert\Type('string'), new Assert\Length(max: 2048)])] public array $evidence = [],
    ) {}
}
