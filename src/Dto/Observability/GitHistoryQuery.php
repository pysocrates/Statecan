<?php

namespace App\Dto\Observability;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class GitHistoryQuery
{
    public function __construct(
        #[Assert\Uuid] public ?string $project = null,
        #[Assert\Uuid] public ?string $repository = null,
        #[Assert\Uuid] public ?string $task = null,
        #[Assert\Length(max: 160)] public string $q = '',
        #[Assert\Length(max: 256)] public string $branch = '',
        #[Assert\Choice(choices: ['all', 'commit', 'merge', 'ref'])] public string $activity = 'all',
        #[Assert\Choice(choices: ['transitions', 'sessions', 'receipts', 'contracts', 'git'])] public string $kind = 'transitions',
        #[Assert\Length(max: 40)] public string $status = '',
        #[Assert\Range(min: 0, max: 10000)] public int $page = 0,
    ) {
    }
}
