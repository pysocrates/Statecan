<?php
namespace App\Dto\Core;
use Symfony\Component\Validator\Constraints as Assert;
final readonly class DeleteTask
{
    public function __construct(#[Assert\Positive] public int $expectedVersion) {}
}
