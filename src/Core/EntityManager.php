<?php

declare(strict_types=1);

namespace Naf\ORM\Core;

use Closure;
use Naf\ORM\Exception\DatabaseException;
use Naf\ORM\Support\DatabaseHelper;
use PDO;
use PDOException;
use ReflectionObject;
use ReflectionProperty;
use RuntimeException;
use Throwable;

class EntityManager
{
    private int $transactionLevel = 0;
    /** @var list<string|null> Null denotes the transaction owned by this manager. */
    private array $transactionFrames = [];

    /**
     * @param PDO $pdo
     */
    public function __construct(protected PDO $pdo)
    {
    }

    /**
     * @return void
     */
    public function begin(): void
    {
        $savepoint = null;
        if (!$this->pdo->inTransaction()) {
            if ($this->transactionLevel !== 0) {
                throw new RuntimeException('The transaction was ended outside this EntityManager.');
            }
            $this->pdo->beginTransaction();
        } else {
            $savepoint = 'NAF_EM_' . spl_object_id($this) . '_' . $this->transactionLevel;
            $this->pdo->exec('SAVEPOINT ' . $savepoint);
        }
        $this->transactionFrames[] = $savepoint;
        ++$this->transactionLevel;
    }

    public function commit(): void
    {
        if ($this->transactionLevel === 0) {
            throw new RuntimeException('Cannot commit without an active transaction.');
        }
        $savepoint = $this->transactionFrames[$this->transactionLevel - 1];
        if ($savepoint === null) {
            $this->pdo->commit();
        } else {
            $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        }
        array_pop($this->transactionFrames);
        --$this->transactionLevel;
    }

    public function rollback(): void
    {
        if ($this->transactionLevel === 0) {
            throw new RuntimeException('Cannot roll back without an active transaction.');
        }
        $savepoint = $this->transactionFrames[$this->transactionLevel - 1];
        if ($savepoint === null) {
            $this->pdo->rollBack();
        } else {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        }
        array_pop($this->transactionFrames);
        --$this->transactionLevel;
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
        $snapshots             = [];
        $states                = [];
        $processedPivots       = [];

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

            $code           = $e->getCode();
            $normalizedCode = is_numeric($code) ? (int) $code : 0;
            $message        = $e->getMessage();

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
        array &$snapshots,
    ): void {
        $objectId = spl_object_id($entity);
        $state    = $states[$objectId] ?? null;

        if ($state === 'persisted' || $state === 'visiting') {
            return;
        }

        $states[$objectId] = 'visiting';
        $relations         = $this->getRelatedEntities($entity);

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
                $foreignKeyChanged = $this->injectForeignKey($relatedEntity, $entity, $snapshots);
                $relatedObjectId   = spl_object_id($relatedEntity);

                if (($states[$relatedObjectId] ?? null) === 'persisted') {
                    if ($foreignKeyChanged) {
                        $this->upsert($relatedEntity);
                    }

                    continue;
                }

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
        $table        = $this->quoteIdentifier($entity->getTableName());
        $fields       = $entity->getFields();
        $primary      = $entity->getPrimaryKey();
        $id           = $entity->getId();
        $quotedFields = [];

        foreach (array_keys($fields) as $field) {
            $quotedFields[$field] = $this->quoteIdentifier($field);
        }

        if ($id === null) {
            $columns      = implode(', ', $quotedFields);
            $placeholders = implode(', ', array_map(fn($k) => ':' . $k, array_keys($fields)));
            $stmt         = $this->pdo->prepare(
                "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})",
            );
            $stmt->execute($fields);
            $lastId = $this->pdo->lastInsertId();
            $entity->setId(is_numeric($lastId) ? (int) $lastId : $lastId);
        } else {
            $assignments = implode(
                ', ',
                array_map(fn($k) => "{$quotedFields[$k]} = :{$k}", array_keys($fields)),
            );
            $fields[$primary] = $id;
            $quotedPrimary    = $this->quoteIdentifier($primary);
            $stmt             = $this->pdo->prepare(
                "UPDATE {$table} SET {$assignments} WHERE {$quotedPrimary} = :{$primary}",
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
        $fk  = $parent->getTableName(true) . '_id';
        $ref = new ReflectionObject($child);

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
     * @return bool Whether the foreign key value changed.
     */
    protected function injectForeignKey(
        EntityInterface $child,
        EntityInterface $parent,
        array &$snapshots,
    ): bool {
        $fk = $parent->getTableName(true) . '_id';
        if ($parent->getId() === null) {
            throw new RuntimeException('Cannot inject foreign key: parent entity has no ID.');
        }

        $this->rememberProperty($child, $fk, $snapshots);
        $property     = (new ReflectionObject($child))->getProperty($fk);
        $currentValue = $property->isInitialized($child) ? $property->getValue($child) : null;

        if ($currentValue === $parent->getId()) {
            return false;
        }

        $property->setValue($child, $parent->getId());

        return true;
    }

    /**
     * @param EntityInterface $a
     * @param EntityInterface $b
     *
     * @return void
     */
    protected function insertPivot(EntityInterface $a, EntityInterface $b): void
    {
        $pivotName = DatabaseHelper::getPivotTableName(
            $a,
            $b,
            $a->getTableName(true),
            $b->getTableName(true),
        );
        $pivot = $this->quoteIdentifier($pivotName);

        $aCol = $this->quoteIdentifier($a->getTableName(true) . '_id');
        $bCol = $this->quoteIdentifier($b->getTableName(true) . '_id');
        $aId  = $a->getId();
        $bId  = $b->getId();

        if ($aId === null || $bId === null) {
            throw new RuntimeException(
                'Cannot persist a pivot relation before both entities have IDs.',
            );
        }

        $sql = "INSERT INTO {$pivot} ({$aCol}, {$bCol}) VALUES (:a, :b)";
        if (DatabaseHelper::getDriverName($this->pdo) === 'pgsql') {
            $sql .= ' ON CONFLICT DO NOTHING';
        }

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':a' => $aId,
                ':b' => $bId,
            ]);
        } catch (PDOException $e) {
            if (!$this->isDuplicateKeyException($e)) {
                throw $e;
            }
        }
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
        array &$snapshots,
    ): void {
        $reflection = new ReflectionObject($entity);
        if (!$reflection->hasProperty($propertyName)) {
            return;
        }

        $key = spl_object_id($entity) . ':' . $propertyName;
        if (isset($snapshots[$key])) {
            return;
        }

        $property        = $reflection->getProperty($propertyName);
        $initialized     = $property->isInitialized($entity);
        $snapshots[$key] = [
            'entity'      => $entity,
            'property'    => $property,
            'initialized' => $initialized,
            'value'       => $initialized ? $property->getValue($entity) : null,
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

            $propertyName  = $snapshot['property']->getName();
            $unsetProperty = Closure::bind(
                function () use ($propertyName): void {
                    unset($this->{$propertyName});
                },
                $snapshot['entity'],
                $snapshot['entity'],
            );
            $unsetProperty();
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        return DatabaseHelper::quoteIdentifier($this->pdo, $identifier);
    }

    private function isDuplicateKeyException(PDOException $exception): bool
    {
        $sqlState   = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message    = strtolower((string) ($exception->errorInfo[2] ?? $exception->getMessage()));

        return match (DatabaseHelper::getDriverName($this->pdo)) {
            'pgsql'  => $sqlState === '23505',
            'mysql'  => $sqlState === '23000' && $driverCode === 1062,
            'sqlite' => $sqlState === '23000'
                && $driverCode === 19
                && str_contains($message, 'unique constraint failed'),
            default => $sqlState === '23505'
                || ($sqlState === '23000' && str_contains($message, 'duplicate')),
        };
    }
}
