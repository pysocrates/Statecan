<?php
namespace App\Dto\Core;
use Symfony\Component\Validator\Constraints as Assert;
final readonly class CreateTask
{
    public function __construct(
        #[Assert\Uuid] public string $projectId,
        #[Assert\NotBlank, Assert\Length(max: 200)] public string $title,
        #[Assert\NotNull] public array $contract,
    ) {}
}
