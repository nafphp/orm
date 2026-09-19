<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Naf\ORM\Core\EntityInterface;
use Naf\ORM\Core\EntityTrait;

class DummyEntity implements EntityInterface
{
    use EntityTrait;

    protected ?int $id     = null;
    protected string $name = 'dummy';
}
