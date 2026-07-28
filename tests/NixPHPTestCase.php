<?php

declare(strict_types=1);

namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use function NixPHP\Database\database;

class NixPHPTestCase extends TestCase
{
    protected static PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$pdo = database();
        self::rebuildSchema();
    }

    protected static function rebuildSchema(): void
    {
        $pdo = self::$pdo;
        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $idColumn = $driver === 'mysql'
            ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY'
            : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $textColumn = $driver === 'mysql' ? 'VARCHAR(255)' : 'TEXT';

        $pdo->exec('DROP TABLE IF EXISTS player_team_links');
        $pdo->exec('DROP TABLE IF EXISTS tasks');
        $pdo->exec('DROP TABLE IF EXISTS players');
        $pdo->exec('DROP TABLE IF EXISTS teams');
        $pdo->exec('DROP TABLE IF EXISTS projects');

        $pdo->exec(
            "CREATE TABLE players (
                id {$idColumn},
                name {$textColumn} NOT NULL,
                age INTEGER NOT NULL
            )"
        );

        $pdo->exec(
            "CREATE TABLE teams (
                id {$idColumn},
                name {$textColumn} NOT NULL
            )"
        );

        $pdo->exec(
            'CREATE TABLE player_team_links (
                player_id INTEGER NOT NULL,
                team_id INTEGER NOT NULL,
                UNIQUE(player_id, team_id)
            )'
        );

        $pdo->exec(
            "CREATE TABLE projects (
                id {$idColumn},
                name {$textColumn} NOT NULL
            )"
        );

        $pdo->exec(
            "CREATE TABLE tasks (
                id {$idColumn},
                title {$textColumn} NOT NULL UNIQUE,
                project_id INTEGER NOT NULL
            )"
        );
    }

    protected function clearFixtures(): void
    {
        $pdo = self::$pdo;
        $pdo->exec('DELETE FROM player_team_links');
        $pdo->exec('DELETE FROM players');
        $pdo->exec('DELETE FROM teams');
        $pdo->exec('DELETE FROM tasks');
        $pdo->exec('DELETE FROM projects');
    }

    /**
     * Ensure fixtures are cleared before every test.
     *
     * Subclasses overriding this method must call parent::setUp() first.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->clearFixtures();
    }
}
