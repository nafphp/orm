<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Naf\ORM\Model\AbstractModel;

class Milestone extends AbstractModel
{
    protected ?int $id = null;
    protected string $title = '';
    protected ?int $portfolio_id = null;
    protected ?int $project_id = null;

    public function getPortfolioId(): ?int
    {
        return $this->portfolio_id;
    }

    public function getProjectId(): ?int
    {
        return $this->project_id;
    }
}
