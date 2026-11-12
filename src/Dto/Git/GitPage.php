<?php

namespace App\Dto\Git;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class GitPage
{
    public function __construct(
        #[Assert\Range(min: 1, max: 200)] public int $limit = 50,
        #[Assert\Range(min: 0, max: 1000000)] public int $offset = 0,
    ) {
    }
}
