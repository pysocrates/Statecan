<?php
namespace App\Dto\Core;
use Symfony\Component\Validator\Constraints as Assert;
final readonly class TransitionTask
{
    public function __construct(
        #[Assert\Choice(choices: ['ready', 'blocked', 'failed', 'cancelled'])] public string $target,
        #[Assert\Positive] public int $expectedVersion,
    ) {}
}
