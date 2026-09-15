<?php

declare(strict_types=1);

namespace Naf\ORM\Core;

interface EntityInterface
{
    /**
     * @return string
     */
    public function getPrimaryKey(): string;

    /**
     * @return array
     */
    public function getFields(): array;

    /**
     * @return array
     */
    public function getRelations(): array;

    /**
     * @return int|string|null
     */
    public function getId(): int|string|null;

    /**
     * @param int|string $id
     *
     * @return void
     */
    public function setId(int|string $id): void;

    /**
     * @param bool $singular
     *
     * @return string
     */
    public function getTableName(bool $singular = false): string;
}
