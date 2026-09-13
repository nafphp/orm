<?php

declare(strict_types=1);

namespace Naf\ORM\Core;

use ReflectionObject;

trait EntityTrait
{
    /**
     * @return string
     */
    public function getPrimaryKey(): string
    {
        return 'id';
    }

    /**
     * @return int|string|null
     */
    public function getId(): int|string|null
    {
        $property = $this->getPrimaryKey();
        return $this->{$property} ?? null;
    }

    /**
     * @param int|string $id
     *
     * @return void
     */
    public function setId(int|string $id): void
    {
        $property = $this->getPrimaryKey();
        $this->{$property} = $id;
    }

    /**
     * @param bool $singular
     *
     * @return string
     */
    public function getTableName(bool $singular = false): string
    {
        $result = strtolower(substr(static::class, strrpos(static::class, '\\') + 1));
        if ($singular) {
            return $result;
        }
        return $result . 's';
    }

    /**
     * @return array
     */
    public function getFields(): array
    {
        $reflection = new ReflectionObject($this);
        $fields = [];

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PROTECTED) as $prop) {
            $name = $prop->getName();

            if ($name === $this->getPrimaryKey()) continue;

            $value = $prop->getValue($this);

            if (is_scalar($value) || $value === null) {
                $fields[$name] = $value;
            }
        }

        return $fields;
    }

    /**
     * @return array
     */
    public function getRelations(): array
    {
        $reflection = new ReflectionObject($this);
        $relations = [];

        foreach ($reflection->getProperties() as $prop) {
            $value = $prop->getValue($this);

            if ($value instanceof EntityInterface && !$this->isAbstract($value)) {
                $relations[$prop->getName()] = $value;
            } elseif ($this->isArrayOfEntities($value)) {
                $relations[$prop->getName()] = $value;
            }
        }

        return $relations;
    }

    /**
     * @param mixed $value
     *
     * @return bool
     */
    private function isArrayOfEntities(mixed $value): bool
    {
        if (!is_array($value) || empty($value)) return false;

        foreach ($value as $v) {
            if (!$v instanceof EntityInterface || $this->isAbstract($v)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param EntityInterface $entity
     *
     * @return bool
     */
    private function isAbstract(EntityInterface $entity): bool
    {
        return (new ReflectionObject($entity))->isAbstract();
    }
}
