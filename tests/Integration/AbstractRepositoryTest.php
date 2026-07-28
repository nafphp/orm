<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Fixtures\Player;
use Tests\Fixtures\PlayerRepository;
use Tests\Fixtures\Team;
use Tests\NixPHPTestCase;
use function NixPHP\ORM\repo;

class AbstractRepositoryTest extends NixPHPTestCase
{
    private PlayerRepository $playerRepository;

    private int $alphaPlayerId;
    private int $teamId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearFixtures();
        $repository = repo(PlayerRepository::class);
        if (!$repository instanceof PlayerRepository) {
            throw new \RuntimeException('Expected a PlayerRepository instance.');
        }
        $this->playerRepository = $repository;
        $this->seedFixtures();
    }

    public function testFindAllReturnsEveryPlayer(): void
    {
        $players = $this->playerRepository->findAll();
        $this->assertCount(2, $players);
        $this->assertContainsOnlyInstancesOf(Player::class, $players);
    }

    public function testFindByFiltersRows(): void
    {
        $matches = $this->playerRepository->findBy('age', 30);
        $this->assertCount(1, $matches);
        $this->assertSame('Beta', $matches[0]->getName());
    }

    public function testFindBySupportsOffsetWithoutLimit(): void
    {
        $matches = $this->playerRepository->findBy(
            [],
            orderBy: ['id' => 'ASC'],
            offset: 1
        );

        $this->assertCount(1, $matches);
        $this->assertSame('Beta', $matches[0]->getName());
    }

    public function testFindOneByReturnsSingleEntity(): void
    {
        $entity = $this->playerRepository->findOneBy('name', 'Alpha');
        $this->assertInstanceOf(Player::class, $entity);
        if (!$entity instanceof Player) {
            $this->fail('Expected a Player entity.');
        }
        $this->assertSame('Alpha', $entity->getName());
    }

    public function testFindByPivotJoinsPlayers(): void
    {
        $players = $this->playerRepository->findByPivot(Team::class, $this->teamId);
        $this->assertCount(1, $players);
        $this->assertInstanceOf(Player::class, $players[0]);
        if (!$players[0] instanceof Player) {
            $this->fail('Expected a Player entity.');
        }
        $this->assertSame('Alpha', $players[0]->getName());
    }

    public function testFindOrCreateByCreatesMissingRow(): void
    {
        $this->assertCount(0, $this->playerRepository->findBy('name', 'Zed'));

        $result = $this->playerRepository->findOrCreateBy('name', 'Zed');

        $this->assertInstanceOf(Player::class, $result);
        if (!$result instanceof Player) {
            $this->fail('Expected a Player entity.');
        }
        $this->assertSame('Zed', $result->getName());
        $count = self::$pdo->prepare('SELECT COUNT(*) FROM players WHERE name = :name');
        $count->execute(['name' => 'Zed']);
        $this->assertSame(1, (int) $count->fetchColumn());
    }

    public function testFindOrCreateManyByCreatesAndReturnsEntities(): void
    {
        $values = ['Alpha', 'Zed'];
        $results = $this->playerRepository->findOrCreateManyBy('name', $values);

        $this->assertCount(2, $results);
        $names = array_map(fn(Player $player) => $player->getName(), $results);
        $this->assertEqualsCanonicalizing($values, $names);
    }

    public function testFindOrCreateManyByCreatesDuplicateInputOnlyOnce(): void
    {
        $results = $this->playerRepository->findOrCreateManyBy('name', ['Zed', 'Zed']);

        $this->assertCount(2, $results);
        $this->assertSame($results[0], $results[1]);

        $count = self::$pdo->query("SELECT COUNT(*) FROM players WHERE name = 'Zed'");
        $this->assertSame(1, (int) $count->fetchColumn());
    }

    private function seedFixtures(): void
    {
        $this->teamId = $this->insertTeam('Red');
        $this->alphaPlayerId = $this->insertPlayer('Alpha', 24);
        $this->insertPlayer('Beta', 30);
        $this->insertPivot($this->alphaPlayerId, $this->teamId);
    }

    private function insertPlayer(string $name, int $age): int
    {
        $stmt = self::$pdo->prepare('INSERT INTO players (name, age) VALUES (:name, :age)');
        $stmt->execute(['name' => $name, 'age' => $age]);
        return (int) self::$pdo->lastInsertId();
    }

    private function insertTeam(string $name): int
    {
        $stmt = self::$pdo->prepare('INSERT INTO teams (name) VALUES (:name)');
        $stmt->execute(['name' => $name]);
        return (int) self::$pdo->lastInsertId();
    }

    private function insertPivot(int $playerId, int $teamId): void
    {
        $stmt = self::$pdo->prepare(
            'INSERT INTO player_team_links (player_id, team_id) VALUES (:player, :team)'
        );
        $stmt->execute(['player' => $playerId, 'team' => $teamId]);
    }
}
