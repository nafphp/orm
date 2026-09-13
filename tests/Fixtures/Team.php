<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Naf\ORM\Model\AbstractModel;
use Tests\Fixtures\Player;

class Team extends AbstractModel
{
    protected ?int $id = null;
    protected string $name = '';
    protected array $players = [];

    public function getName(): string
    {
        return $this->name;
    }

    public function addPlayer(Player $player): void
    {
        foreach ($this->players as $existing) {
            if ($existing === $player) {
                return;
            }
        }

        $this->players[] = $player;
    }

    public function getPlayers(): array
    {
        return $this->players;
    }
}
