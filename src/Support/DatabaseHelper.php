<?php

declare(strict_types=1);

namespace Naf\ORM\Support;

use InvalidArgumentException;
use Naf\ORM\Core\EntityInterface;
use PDO;
use ReflectionObject;

final class DatabaseHelper
{
    public static function getDriverName(PDO $pdo): string
    {
        return strtolower((string) ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) ?? ''));
    }

    public static function getIdentifierQuote(PDO $pdo): string
    {
        return self::getDriverName($pdo) === 'mysql' ? '`' : '"';
    }

    public static function quoteIdentifier(PDO $pdo, string $identifier): string
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier) !== 1) {
            throw new InvalidArgumentException("Invalid identifier: {$identifier}");
        }

        $quote = self::getIdentifierQuote($pdo);

        return $quote . $identifier . $quote;
    }

    public static function getPivotTableName(
        EntityInterface $a,
        EntityInterface $b,
        string $aTable,
        string $bTable
    ): string {
        $aMappings = self::getPivotTableMappings($a);
        if (isset($aMappings[$b::class])) {
            return $aMappings[$b::class];
        }

        $bMappings = self::getPivotTableMappings($b);
        if (isset($bMappings[$a::class])) {
            return $bMappings[$a::class];
        }

        $tables = [$aTable, $bTable];
        sort($tables);

        return implode('_', $tables);
    }

    /**
     * @return array<string, string>
     */
    private static function getPivotTableMappings(EntityInterface $entity): array
    {
        $reflection = new ReflectionObject($entity);
        if (!$reflection->hasProperty('pivotTables')) {
            return [];
        }

        $property = $reflection->getProperty('pivotTables');
        if (!$property->isInitialized($entity)) {
            return [];
        }

        $rawMappings = $property->getValue($entity);
        if (!is_array($rawMappings)) {
            return [];
        }

        $mappings = [];
        foreach ($rawMappings as $class => $table) {
            if (is_string($class) && is_string($table)) {
                $mappings[$class] = $table;
            }
        }

        return $mappings;
    }
}
