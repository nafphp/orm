<?php

declare(strict_types=1);

use Naf\Core\Container;
use Naf\ORM\Core\EntityManager;
use Naf\ORM\Repository\RepositoryFactory;
use Naf\Database\Core\Database;
use function Naf\app;

app()->container()->set(
    EntityManager::class,
    fn(Container $container) =>
        new EntityManager($container->get(Database::class)->getConnection())
);

app()->container()->set(
    RepositoryFactory::class,
    fn(Container $container) =>
        new RepositoryFactory(
            $container->get(Database::class)->getConnection(),
            $container->get(EntityManager::class)
        )
);