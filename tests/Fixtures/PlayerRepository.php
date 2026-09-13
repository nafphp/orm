<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Naf\ORM\Repository\AbstractRepository;

class PlayerRepository extends AbstractRepository
{
    protected function getEntityClass(): string
    {
        return Player::class;
    }
}
