<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Naf\ORM\Model\AbstractModel;

class Player extends AbstractModel
{
    protected ?int $id        = null;
    protected string $name    = '';
    protected int $age        = 0;
    protected array $teams    = [];
    public array $pivotTables = [
        Team::class => 'player_team_links',
    ];

    public function getName(): string
    {
        return $this->name;
    }

    public function getAge(): int
    {
        return $this->age;
    }

    public function setAge(int $age): void
    {
        $this->age = $age;
    }

    public function addTeam(Team $team): void
    {
        foreach ($this->teams as $existing) {
            if ($existing === $team) {
                return;
            }
        }

        $this->teams[] = $team;
    }

    public function getTeams(): array
    {
        return $this->teams;
    }
}
