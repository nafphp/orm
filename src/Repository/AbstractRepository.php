<?php

declare(strict_types=1);

namespace NixPHP\ORM\Repository;

use Exception;
use InvalidArgumentException;
use NixPHP\ORM\Core\EntityInterface;
use NixPHP\ORM\Core\EntityManager;
use NixPHP\ORM\Exception\DatabaseException;
use NixPHP\ORM\Support\DatabaseHelper;
use NixPHP\Decorators\AutoResolvingContainer;
use PDO;
use RuntimeException;
use function NixPHP\app;

abstract class AbstractRepository
{
    /**
     * @var array<int, string>
     */
    protected array $allowedColumns = [];

    private array $columnWhitelistCache = [];

    public function __construct(
        protected PDO $pdo,
        protected EntityManager $entityManager
    ) {
    }

    /**
     * @return class-string<EntityInterface>
     */
    abstract protected function getEntityClass(): string;

    /**
     * @return EntityInterface
     */
    protected function getEntity(): EntityInterface
    {
        $class = $this->getEntityClass();
        $container = app()->container();
        if (!$container instanceof AutoResolvingContainer) {
            throw new RuntimeException('ORM repositories require an auto-resolving container.');
        }

        return $container->make($class);
    }

    /**
     * @param bool $singular
     *
     * @return string
     */
    protected function getTable(bool $singular = false): string
    {
        $entity = $this->getEntity();

        $table = $entity->table
            ?? strtolower(basename(str_replace('\\', '/', $this->getEntityClass()))) . 's';

        if ($singular && str_ends_with($table, 's')) {
            return substr($table, 0, -1); // naive Singularform
        }

        return $table;
    }

    /**
     * @param class-string<EntityInterface> $relatedClass
     * @param string $selfTable
     * @param string $relatedTable
     *
     * @return string
     */
    protected function getPivotTable(string $relatedClass, string $selfTable, string $relatedTable): string
    {
        $entity = $this->getEntity();
        $relatedEntity = new $relatedClass();
        if (!$relatedEntity instanceof EntityInterface) {
            throw new InvalidArgumentException(
                "{$relatedClass} must implement " . EntityInterface::class
            );
        }

        return DatabaseHelper::getPivotTableName(
            $entity,
            $relatedEntity,
            $selfTable,
            $relatedTable
        );
    }

    protected function getColumnWhitelist(): array
    {
        if ($this->allowedColumns !== []) {
            return $this->allowedColumns;
        }

        if ($this->columnWhitelistCache !== []) {
            return $this->columnWhitelistCache;
        }

        $entity = $this->getEntity();
        $fields = array_keys($entity->getFields());
        $this->columnWhitelistCache = array_unique(array_merge([$entity->getPrimaryKey()], $fields));
        return $this->columnWhitelistCache;
    }

    protected function quoteIdentifier(string $identifier): string
    {
        return DatabaseHelper::quoteIdentifier($this->pdo, $identifier);
    }

    protected function getIdentifierQuote(): string
    {
        return DatabaseHelper::getIdentifierQuote($this->pdo);
    }

    protected function getDriverName(): string
    {
        return DatabaseHelper::getDriverName($this->pdo);
    }

    protected function quoteColumn(string $column): string
    {
        if (!in_array($column, $this->getColumnWhitelist(), true)) {
            throw new InvalidArgumentException("Column not allowed: {$column}");
        }

        return $this->quoteIdentifier($column);
    }

    protected function quoteTableName(string $table): string
    {
        return $this->quoteIdentifier($table);
    }

    /**
     * @return array
     */
    public function findAll(): array
    {
        $table = $this->quoteTableName($this->getTable());
        $sql = "SELECT * FROM {$table}";
        $stmt = $this->pdo->query($sql);
        $rows = $stmt->fetchAll();

        return $this->hydrateMany($rows);
    }

    /**
     * @param array|string $criteria
     * @param mixed|null   $value
     * @param array        $orderBy
     * @param int|null     $limit
     * @param int|null     $offset
     *
     * @return array
     */
    public function findBy(
        array|string $criteria,
        mixed $value   = null,
        array $orderBy = [],
        ?int $limit    = null,
        ?int $offset   = null
    ): array {
        $table = $this->quoteTableName($this->getTable());
        $sql = "SELECT * FROM {$table} WHERE 1=1";
        $params = [];

        if (is_string($criteria)) {
            $criteria = [$criteria => $value];
        }

        foreach ($criteria as $field => $val) {
            $column = $this->quoteColumn($field);
            $sql .= " AND {$column} = :{$field}";
            $params[":{$field}"] = $val;
        }

        if (!empty($orderBy)) {
            $parts = [];
            foreach ($orderBy as $field => $dir) {
                $dir = strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC';
                $quoted = $this->quoteColumn($field);
                $parts[] = "{$quoted} {$dir}";
            }
            $sql .= " ORDER BY " . implode(', ', $parts);
        }

        if ($limit !== null) {
            $sql .= " LIMIT " . (int)$limit;
        } elseif ($offset !== null) {
            $sql .= match ($this->getDriverName()) {
                'mysql' => ' LIMIT 18446744073709551615',
                'sqlite' => ' LIMIT -1',
                default => '',
            };
        }
        if ($offset !== null) {
            $sql .= " OFFSET " . (int)$offset;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $this->hydrateMany($stmt->fetchAll());
    }

    /**
     * @param array|string $criteria
     * @param mixed|null   $value
     * @param array        $orderBy
     *
     * @return EntityInterface|null
     */
    public function findOneBy(
        array|string $criteria,
        mixed $value   = null,
        array $orderBy = []
    ): ?EntityInterface {
        $results = $this->findBy($criteria, $value, $orderBy, limit: 1);
        return $results[0] ?? null;
    }

    /**
     * @param string $pivotWithClass
     * @param int    $pivotId
     *
     * @return array
     */
    public function findByPivot(string $pivotWithClass, int $pivotId): array
    {
        if (!is_subclass_of($pivotWithClass, EntityInterface::class)) {
            throw new InvalidArgumentException(
                "{$pivotWithClass} must implement " . EntityInterface::class
            );
        }

        $thisTable     = $this->getTable(true);
        $relatedTable  = strtolower(basename(str_replace('\\', '/', $pivotWithClass)));

        $pivotTable    = $this->getPivotTable($pivotWithClass, $thisTable, $relatedTable);

        $colSelf       = $thisTable . '_id';
        $colPivot      = $relatedTable . '_id';

        $pluralTable = $this->getTable();
        $quotedMainTable = $this->quoteTableName($pluralTable);
        $quotedPivotTable = $this->quoteTableName($pivotTable);
        $entity = $this->getEntity();
        $primaryKey = $entity->getPrimaryKey();
        $quotedIdColumn = $this->quoteIdentifier($primaryKey);
        $quotedColSelf = $this->quoteIdentifier($colSelf);
        $quotedColPivot = $this->quoteIdentifier($colPivot);

        $sql = "SELECT {$quotedMainTable}.* FROM {$quotedMainTable}
            INNER JOIN {$quotedPivotTable} ON {$quotedPivotTable}.{$quotedColSelf} = {$quotedMainTable}.{$quotedIdColumn}
            WHERE {$quotedPivotTable}.{$quotedColPivot} = :id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $pivotId]);

        return $this->hydrateMany($stmt->fetchAll());
    }

    /**
     * @param string $field
     * @param mixed  $value
     *
     * @return EntityInterface
     * @throws Exception
     */
    public function findOrCreateBy(string $field, mixed $value): EntityInterface
    {
        return $this->findOneBy($field, $value) ?? $this->create($field, $value);
    }

    /**
     * @param string $field
     * @param array  $values
     *
     * @return array
     */
    public function findOrCreateManyBy(string $field, array $values): array
    {
        if (empty($values)) return [];

        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $table = $this->quoteTableName($this->getTable());
        $column = $this->quoteColumn($field);
        $sql = "SELECT * FROM {$table} WHERE {$column} IN ($placeholders)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
        $rows = $stmt->fetchAll();

        $existing = [];
        foreach ($rows as $row) {
            $key = $row[$field];
            $existing[$key] = $this->hydrate($row);
        }

        $missing = array_unique(
            array_diff($values, array_keys($existing)),
            SORT_REGULAR
        );

        foreach ($missing as $value) {
            $entity = $this->create($field, $value);
            $existing[$value] = $entity;
        }

        return array_map(fn($val) => $existing[$val], $values);
    }

    /**
     * @param string $field
     * @param mixed  $value
     *
     * @return EntityInterface
     * @throws DatabaseException
     */
    protected function create(string $field, mixed $value): EntityInterface
    {
        $class = $this->getEntityClass();
        $entity = new $class([$field => $value]);
        $this->entityManager->save($entity);
        return $entity;
    }

    /**
     * @param array $row
     *
     * @return EntityInterface
     */
    protected function hydrate(array $row): EntityInterface
    {
        $class = $this->getEntityClass();
        return new $class($row);
    }

    /**
     * @param array $rows
     *
     * @return array
     */
    protected function hydrateMany(array $rows): array
    {
        return array_map(fn($row) => $this->hydrate($row), $rows);
    }
}
