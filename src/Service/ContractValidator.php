<?php

namespace App\Service;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** The matching public JSON Schema is schemas/task-contract-v1.json. */
final readonly class ContractValidator
{
    public function __construct(private ValidatorInterface $validator) {}

    public function validate(array $document): void
    {
        $list = new Assert\Sequentially([new Assert\Type('array'), new Assert\All([new Assert\Type('string'), new Assert\NotBlank()])]);
        $violations = $this->validator->validate($document, new Assert\Collection([
            'schemaVersion' => new Assert\IdenticalTo(1),
            'objective' => [new Assert\Type('string'), new Assert\NotBlank()],
            'authorizedScope' => $list,
            'constraints' => $list,
            'dependencies' => new Assert\Sequentially([new Assert\Type('array'), new Assert\All(new Assert\Uuid())]),
            'acceptanceCriteria' => $list,
            'expectedDeliverables' => $list,
            'resourceBudgets' => new Assert\Optional(new Assert\Type('array')),
        ]));
        if (count($violations)) {
            throw new \InvalidArgumentException((string) $violations);
        }
        foreach ($document['resourceBudgets'] ?? [] as $name => $budget) {
            if (!is_string($name) || (!is_int($budget) && !is_float($budget)) || $budget < 0 || !is_finite((float) $budget)) {
                throw new \InvalidArgumentException('Resource budgets must be named nonnegative numbers.');
            }
        }
        foreach (['authorizedScope', 'constraints', 'dependencies', 'acceptanceCriteria', 'expectedDeliverables'] as $field) {
            if (!array_is_list($document[$field])) {
                throw new \InvalidArgumentException($field.' must be a JSON list.');
            }
        }
    }
}
