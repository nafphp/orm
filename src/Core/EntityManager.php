<?php

declare(strict_types=1);

namespace NixPHP\ORM\Core;

use InvalidArgumentException;
use NixPHP\ORM\Exception\DatabaseException;
use PDO;
use ReflectionObject;
use ReflectionProperty;
use RuntimeException;
use Throwable;

class EntityManager
{
    private int $transactionLevel = 0;

    /**
     * @param PDO $pdo
     */
    public function __construct(
        protected PDO $pdo
    ) {}

    /**
     * @return void
     */
    public function begin(): void
    {
        if ($this->transactionLevel === 0) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec("SAVEPOINT LEVEL{$this->transactionLevel}");
        }

        $this->transactionLevel++;
    }

    /**
     * @return void
     */
    public function commit(): void
    {
        if ($this->transactionLevel === 0) {
            throw new RuntimeException('Cannot commit without an active transaction.');
        }

        $targetLevel = $this->transactionLevel - 1;

        if ($targetLevel === 0) {
            $this->pdo->commit();
        } else {
            $this->pdo->exec("RELEASE SAVEPOINT LEVEL{$targetLevel}");
        }

        $this->transactionLevel = $targetLevel;
    }

    /**
     * @return void
     */
    public function rollback(): void
    {
        if ($this->transactionLevel === 0) {
            throw new RuntimeException('Cannot roll back without an active transaction.');
        }

        $targetLevel = $this->transactionLevel - 1;

        if ($targetLevel === 0) {
            $this->pdo->rollBack();
        } else {
            $savepoint = "LEVEL{$targetLevel}";
            $this->pdo->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
            $this->pdo->exec("RELEASE SAVEPOINT {$savepoint}");
        }

        $this->transactionLevel = $targetLevel;
    }

    /**
     * @param EntityInterface $root
     *
     * @return void
     * @throws DatabaseException
     */
    public function save(EntityInterface $root): void
    {
        $entryTransactionLevel = $this->transactionLevel;
        $snapshots = [];
        $states = [];
        $processedPivots = [];

        $this->begin();

        try {
            $this->persistEntity($root, $states, $processedPivots, $snapshots);

            $this->commit();
        } catch (Throwable $e) {
            $rollbackException = null;

            try {
                if ($this->transactionLevel > $entryTransactionLevel) {
                    $this->rollback();
                }
            } catch (Throwable $rollbackError) {
                $rollbackException = $rollbackError;
            } finally {
                $this->restoreSnapshots($snapshots);
            }

            $code = $e->getCode();
            $normalizedCode = is_numeric($code) ? (int)$code : 0;
            $message = $e->getMessage();

            if ($rollbackException !== null) {
                $message .= ' Rollback failed: ' . $rollbackException->getMessage();
            }

            throw new DatabaseException($message, $normalizedCode, $e);
        }
    }

    public function clear(): void
    {
        if ($this->transactionLevel > 0) {
            throw new RuntimeException('Cannot clear EntityManager while a transaction is active.');
        }

        $this->transactionLevel = 0;
    }

    /**
     * @param EntityInterface $entity
     * @param array<int, string> $states
     * @param array<string, true> $processedPivots
     * @param array<string, array{
     *     entity: EntityInterface,
     *     property: ReflectionProperty,
     *     initialized: bool,
     *     value: mixed
     * }> $snapshots
     *
     * @return void
     */
    protected function persistEntity(
        EntityInterface $entity,
        array &$states,
        array &$processedPivots,
        array &$snapshots
    ): void {
        $objectId = spl_object_id($entity);
        $state = $states[$objectId] ?? null;

        if ($state === 'persisted' || $state === 'visiting') {
            return;
        }

        $states[$objectId] = 'visiting';
        $relations = $this->getRelatedEntities($entity);

        // A to-one relation whose foreign key lives on the current entity must
        // exist before the current entity can be inserted.
        foreach ($relations as $relatedEntity) {
            if (!$this->hasForeignKeyFor($entity, $relatedEntity)) {
                continue;
            }

            $this->persistEntity($relatedEntity, $states, $processedPivots, $snapshots);
            $this->injectForeignKey($entity, $relatedEntity, $snapshots);
        }

        $this->rememberProperty($entity, $entity->getPrimaryKey(), $snapshots);
        $this->upsert($entity);
        $states[$objectId] = 'persisted';

        foreach ($relations as $relatedEntity) {
            if ($this->hasForeignKeyFor($relatedEntity, $entity)) {
                $this->injectForeignKey($relatedEntity, $entity, $snapshots);
                $this->persistEntity($relatedEntity, $states, $processedPivots, $snapshots);
                continue;
            }

            if ($this->hasForeignKeyFor($entity, $relatedEntity)) {
                continue;
            }

            $this->persistEntity($relatedEntity, $states, $processedPivots, $snapshots);

            $pivotKey = $this->getPivotKey($entity, $relatedEntity);
            if (isset($processedPivots[$pivotKey])) {
                continue;
            }

            $this->insertPivot($entity, $relatedEntity);
            $processedPivots[$pivotKey] = true;
        }
    }

    /**
     * @param EntityInterface $entity
     *
     * @return void
     */
    protected function upsert(EntityInterface $entity): void
    {
        $table = $this->quoteIdentifier($entity->getTableName());
        $fields = $entity->getFields();
        $primary = $entity->getPrimaryKey();
        $id = $entity->getId();
        $quotedFields = [];

        foreach (array_keys($fields) as $field) {
            $quotedFields[$field] = $this->quoteIdentifier($field);
        }

        if ($id === null) {
            $columns = implode(', ', $quotedFields);
            $placeholders = implode(', ', array_map(fn($k) => ':' . $k, array_keys($fields)));
            $stmt = $this->pdo->prepare("INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})");
            $stmt->execute($fields);
            $lastId = $this->pdo->lastInsertId();
            $entity->setId(is_numeric($lastId) ? (int) $lastId : $lastId);
        } else {
            $assignments = implode(', ', array_map(
                fn($k) => "{$quotedFields[$k]} = :{$k}",
                array_keys($fields)
            ));
            $fields[$primary] = $id;
            $quotedPrimary = $this->quoteIdentifier($primary);
            $stmt = $this->pdo->prepare(
                "UPDATE {$table} SET {$assignments} WHERE {$quotedPrimary} = :{$primary}"
            );
            $stmt->execute($fields);
        }
    }

    /**
     * @param EntityInterface $child
     * @param EntityInterface $parent
     *
     * @return bool
     */
    protected function hasForeignKeyFor(EntityInterface $child, EntityInterface $parent): bool
    {
        $fk = $parent->getTableName(true) . '_id';
        $ref = new \ReflectionClass($child);
        return $ref->hasProperty($fk);
    }

    /**
     * @param EntityInterface $child
     * @param EntityInterface $parent
     * @param array<string, array{
     *     entity: EntityInterface,
     *     property: ReflectionProperty,
     *     initialized: bool,
     *     value: mixed
     * }> $snapshots
     *
     * @return void
     */
    protected function injectForeignKey(
        EntityInterface $child,
        EntityInterface $parent,
        array &$snapshots
    ): void
    {
        $fk = $parent->getTableName(true) . '_id';
        if ($parent->getId() === null) {
            throw new \RuntimeException("Cannot inject foreign key: parent entity has no ID.");
        }

        $this->rememberProperty($child, $fk, $snapshots);
        $property = (new ReflectionObject($child))->getProperty($fk);
        $property->setValue($child, $parent->getId());
    }

    /**
     * @param EntityInterface $a
     * @param EntityInterface $b
     *
     * @return void
     */
    protected function insertPivot(EntityInterface $a, EntityInterface $b): void
    {
        $pivot = $this->quoteIdentifier($this->getPivotTableName($a, $b));

        $aCol = $this->quoteIdentifier($a->getTableName(true) . '_id');
        $bCol = $this->quoteIdentifier($b->getTableName(true) . '_id');
        $aId = $a->getId();
        $bId = $b->getId();

        if ($aId === null || $bId === null) {
            throw new RuntimeException('Cannot persist a pivot relation before both entities have IDs.');
        }

        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM {$pivot} WHERE {$aCol} = :a AND {$bCol} = :b"
        );
        $stmt->execute([
            ':a' => $aId,
            ':b' => $bId,
        ]);

        if ($stmt->fetchColumn() !== false) {
            return;
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO {$pivot} ({$aCol}, {$bCol}) VALUES (:a, :b)"
        );
        $stmt->execute([
            ':a' => $aId,
            ':b' => $bId,
        ]);
    }

    /**
     * @return array<int, EntityInterface>
     */
    private function getRelatedEntities(EntityInterface $entity): array
    {
        $entities = [];

        foreach ($entity->getRelations() as $related) {
            $relatedItems = is_array($related) ? $related : [$related];

            foreach ($relatedItems as $relatedEntity) {
                if ($relatedEntity instanceof EntityInterface) {
                    $entities[] = $relatedEntity;
                }
            }
        }

        return $entities;
    }

    /**
     * @param EntityInterface $a
     * @param EntityInterface $b
     *
     * @return string
     */
    private function getPivotKey(EntityInterface $a, EntityInterface $b): string
    {
        $objectIds = [spl_object_id($a), spl_object_id($b)];
        sort($objectIds);

        return implode(':', $objectIds);
    }

    private function getPivotTableName(EntityInterface $a, EntityInterface $b): string
    {
        $aMappings = $this->getPivotTableMappings($a);
        if (isset($aMappings[$b::class]) && is_string($aMappings[$b::class])) {
            return $aMappings[$b::class];
        }

        $bMappings = $this->getPivotTableMappings($b);
        if (isset($bMappings[$a::class]) && is_string($bMappings[$a::class])) {
            return $bMappings[$a::class];
        }

        $tables = [$a->getTableName(true), $b->getTableName(true)];
        sort($tables);

        return implode('_', $tables);
    }

    /**
     * @return array<class-string<EntityInterface>, string>
     */
    private function getPivotTableMappings(EntityInterface $entity): array
    {
        $reflection = new ReflectionObject($entity);
        if (!$reflection->hasProperty('pivotTables')) {
            return [];
        }

        $property = $reflection->getProperty('pivotTables');
        if (!$property->isInitialized($entity)) {
            return [];
        }

        $mappings = $property->getValue($entity);

        return is_array($mappings) ? $mappings : [];
    }

    /**
     * @param EntityInterface $entity
     * @param string $propertyName
     * @param array<string, array{
     *     entity: EntityInterface,
     *     property: ReflectionProperty,
     *     initialized: bool,
     *     value: mixed
     * }> $snapshots
     *
     * @return void
     */
    private function rememberProperty(
        EntityInterface $entity,
        string $propertyName,
        array &$snapshots
    ): void
    {
        $reflection = new ReflectionObject($entity);
        if (!$reflection->hasProperty($propertyName)) {
            return;
        }

        $key = spl_object_id($entity) . ':' . $propertyName;
        if (isset($snapshots[$key])) {
            return;
        }

        $property = $reflection->getProperty($propertyName);
        $initialized = $property->isInitialized($entity);
        $snapshots[$key] = [
            'entity' => $entity,
            'property' => $property,
            'initialized' => $initialized,
            'value' => $initialized ? $property->getValue($entity) : null,
        ];
    }

    /**
     * @param array<string, array{
     *     entity: EntityInterface,
     *     property: ReflectionProperty,
     *     initialized: bool,
     *     value: mixed
     * }> $snapshots
     *
     * @return void
     */
    private function restoreSnapshots(array $snapshots): void
    {
        foreach (array_reverse($snapshots) as $snapshot) {
            if ($snapshot['initialized']) {
                $snapshot['property']->setValue($snapshot['entity'], $snapshot['value']);
                continue;
            }

            $propertyName = $snapshot['property']->getName();
            $unsetProperty = \Closure::bind(
                function () use ($propertyName): void {
                    unset($this->{$propertyName});
                },
                $snapshot['entity'],
                $snapshot['entity']
            );
            $unsetProperty();
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
            throw new InvalidArgumentException("Invalid identifier: {$identifier}");
        }

        $driver = strtolower((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $quote = $driver === 'mysql' ? '`' : '"';

        return $quote . $identifier . $quote;
    }
}
