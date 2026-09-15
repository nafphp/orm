<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Naf\ORM\Model\AbstractModel;

class Project extends AbstractModel
{
    protected ?int $id           = null;
    protected string $name       = '';
    protected ?int $portfolio_id = null;

    /**
     * @var array<int, Task>
     */
    protected array $tasks = [];

    /**
     * @var array<int, Milestone>
     */
    protected array $milestones = [];

    public function addTask(Task $task): void
    {
        $this->tasks[] = $task;
        $task->setProject($this);
    }

    /**
     * @return array<int, Task>
     */
    public function getTasks(): array
    {
        return $this->tasks;
    }

    public function addMilestone(Milestone $milestone): void
    {
        $this->milestones[] = $milestone;
    }
}
