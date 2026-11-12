<?php

namespace App\Dto\Git;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class RegisterRepository
{
    public function __construct(
        #[Assert\NotBlank, Assert\Uuid] public string $projectId,
        #[Assert\NotBlank, Assert\Length(max: 1024)] public string $path,
    ) {
    }
}
