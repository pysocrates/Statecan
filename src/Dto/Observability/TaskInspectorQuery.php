<?php

namespace App\Dto\Observability;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class TaskInspectorQuery
{
    public function __construct(
        #[Assert\Uuid] public ?string $project = null,
        #[Assert\PositiveOrZero, Assert\LessThanOrEqual(10000)] public int $page = 0,
        #[Assert\Positive] public ?int $contractVersion = null,
    ) {}
}
