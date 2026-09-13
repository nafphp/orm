<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Naf\ORM\Model\AbstractModel;

class Task extends AbstractModel
{
    protected ?int $id = null;
    protected string $title = '';
    protected ?int $project_id = null;
    protected ?Project $project = null;

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getProjectId(): ?int
    {
        return $this->project_id;
    }

    public function setProject(Project $project): void
    {
        $this->project = $project;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }
}
