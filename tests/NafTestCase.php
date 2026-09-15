<?php

declare(strict_types=1);

namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase;

use function Naf\Database\database;

class NafTestCase extends TestCase
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
        $pdo      = self::$pdo;
        $driver   = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $idColumn = $driver === 'mysql'
            ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY'
            : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $foreignIdColumn = $driver === 'mysql' ? 'BIGINT UNSIGNED' : 'INTEGER';
        $textColumn      = $driver === 'mysql' ? 'VARCHAR(255)' : 'TEXT';

        if ($driver === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }

        $pdo->exec('DROP TABLE IF EXISTS player_team_links');
        $pdo->exec('DROP TABLE IF EXISTS milestones');
        $pdo->exec('DROP TABLE IF EXISTS tasks');
        $pdo->exec('DROP TABLE IF EXISTS players');
        $pdo->exec('DROP TABLE IF EXISTS teams');
        $pdo->exec('DROP TABLE IF EXISTS projects');
        $pdo->exec('DROP TABLE IF EXISTS portfolios');

        $pdo->exec(
            "CREATE TABLE players (
                id {$idColumn},
                name {$textColumn} NOT NULL,
                age INTEGER NOT NULL
            )",
        );

        $pdo->exec(
            "CREATE TABLE teams (
                id {$idColumn},
                name {$textColumn} NOT NULL
            )",
        );

        $pdo->exec(
            "CREATE TABLE player_team_links (
                player_id {$foreignIdColumn} NOT NULL,
                team_id {$foreignIdColumn} NOT NULL,
                UNIQUE(player_id, team_id),
                FOREIGN KEY (player_id) REFERENCES players(id),
                FOREIGN KEY (team_id) REFERENCES teams(id)
            )",
        );

        $pdo->exec(
            "CREATE TABLE projects (
                id {$idColumn},
                name {$textColumn} NOT NULL,
                portfolio_id INTEGER NULL
            )",
        );

        $pdo->exec(
            "CREATE TABLE tasks (
                id {$idColumn},
                title {$textColumn} NOT NULL UNIQUE,
                project_id INTEGER NOT NULL
            )",
        );

        $pdo->exec(
            "CREATE TABLE portfolios (
                id {$idColumn},
                name {$textColumn} NOT NULL
            )",
        );

        $pdo->exec(
            "CREATE TABLE milestones (
                id {$idColumn},
                title {$textColumn} NOT NULL,
                portfolio_id INTEGER NULL,
                project_id INTEGER NULL
            )",
        );
    }

    protected function clearFixtures(): void
    {
        $pdo = self::$pdo;
        $pdo->exec('DELETE FROM player_team_links');
        $pdo->exec('DELETE FROM players');
        $pdo->exec('DELETE FROM teams');
        $pdo->exec('DELETE FROM milestones');
        $pdo->exec('DELETE FROM tasks');
        $pdo->exec('DELETE FROM projects');
        $pdo->exec('DELETE FROM portfolios');
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
