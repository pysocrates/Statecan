<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'task_dependency')]
#[ORM\UniqueConstraint(columns: ['task_id', 'depends_on_id'])]
class TaskDependency
{
    #[ORM\Id, ORM\Column(length: 36)]
    private string $id;
    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)] private Task $task,
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)] private Task $dependsOn,
    ) { $this->id = Uuid::v7()->toRfc4122(); }
    public function getId(): string { return $this->id; }
    public function getTask(): Task { return $this->task; }
    public function getDependsOn(): Task { return $this->dependsOn; }
}
