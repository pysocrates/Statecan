<?php

namespace App\Event;

use App\Entity\Task;

/** Dispatched under the task row lock before operator edits; lease module must reject live owners. */
final readonly class OperatorTaskMutation
{
    public function __construct(public Task $task) {}
}
