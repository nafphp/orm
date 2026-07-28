<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use NixPHP\ORM\Model\AbstractModel;

class Portfolio extends AbstractModel
{
    protected ?int $id = null;
    protected string $name = '';

    /**
     * Kept before projects to exercise a child that is persisted before a
     * second parent injects another foreign key.
     *
     * @var array<int, Milestone>
     */
    protected array $milestones = [];

    /**
     * @var array<int, Project>
     */
    protected array $projects = [];

    public function addMilestone(Milestone $milestone): void
    {
        $this->milestones[] = $milestone;
    }

    public function addProject(Project $project): void
    {
        $this->projects[] = $project;
    }
}
