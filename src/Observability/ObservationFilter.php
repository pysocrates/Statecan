<?php

namespace App\Observability;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ObservationFilter
{
    public function __construct(
        #[Assert\Uuid] public ?string $project = null,
        #[Assert\Uuid] public ?string $task = null,
        #[Assert\PositiveOrZero, Assert\LessThanOrEqual(10000)] public int $page = 0,
    ) {
    }
}
