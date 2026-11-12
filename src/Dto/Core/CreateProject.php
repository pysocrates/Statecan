<?php
namespace App\Dto\Core;
use Symfony\Component\Validator\Constraints as Assert;
final readonly class CreateProject
{
    public function __construct(#[Assert\NotBlank, Assert\Length(max: 200)] public string $name) {}
}
