<?php
namespace App\Dto\Core;
use Symfony\Component\Validator\Constraints as Assert;
final readonly class ReviseTask
{
    public function __construct(
        #[Assert\NotBlank, Assert\Length(max: 200)] public string $title,
        #[Assert\Positive] public int $expectedVersion,
        public ?array $contract = null,
    ) {}
}
