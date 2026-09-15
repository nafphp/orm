<?php

namespace Naf\ORM;

use Naf\ORM\Core\EntityManager;
use Naf\ORM\Repository\AbstractRepository;
use Naf\ORM\Repository\RepositoryFactory;

use function Naf\app;

function em(): EntityManager
{
    return app()->container()->get(EntityManager::class);
}

/**
 * @template T of AbstractRepository
 * @param class-string<T> $repository
 *
 * @return T
 */
function repo(string $repository): AbstractRepository
{
    return app()->container()->get(RepositoryFactory::class)->create($repository);
}
