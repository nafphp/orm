<?php

declare(strict_types=1);

namespace NixPHP\ORM\Repository;

use InvalidArgumentException;
use NixPHP\Decorators\AutoResolvingContainer;
use NixPHP\ORM\Core\EntityManager;
use PDO;
use RuntimeException;
use function NixPHP\app;

class RepositoryFactory
{
    protected EntityManager $entityManager;

    /**
     * @var array<class-string<AbstractRepository>, AbstractRepository>
     */
    private array $instances = [];

    public function __construct(
        protected PDO $pdo,
        EntityManager $entityManager
    ) {
        $this->entityManager = $entityManager;
    }

    /**
     * @template T of AbstractRepository
     * @param class-string<T> $repository
     *
     * @return T
     */
    public function create(string $repository): AbstractRepository
    {
        if (!class_exists($repository)) {
            throw new InvalidArgumentException("Repository {$repository} does not exist.");
        }

        if (!is_subclass_of($repository, AbstractRepository::class)) {
            throw new InvalidArgumentException("{$repository} must extend " . AbstractRepository::class);
        }

        $container = app()->container();
        if (!$container instanceof AutoResolvingContainer) {
            throw new RuntimeException('RepositoryFactory requires an auto-resolving container.');
        }

        if (isset($this->instances[$repository])) {
            return $this->instances[$repository];
        }

        $instance = $container->make($repository, [
            $this->pdo,
            $this->entityManager,
        ]);
        $this->instances[$repository] = $instance;

        return $instance;
    }
}
