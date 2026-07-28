<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use NixPHP\ORM\Model\AbstractModel;

class Project extends AbstractModel
{
    protected ?int $id = null;
    protected string $name = '';

    /**
     * @var array<int, Task>
     */
    protected array $tasks = [];

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
}
