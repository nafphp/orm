<?php

declare(strict_types=1);

namespace Tests\Integration;

use Naf\ORM\Exception\DatabaseException;
use RuntimeException;
use Tests\Fixtures\Milestone;
use Tests\Fixtures\Player;
use Tests\Fixtures\Portfolio;
use Tests\Fixtures\Project;
use Tests\Fixtures\Task;
use Tests\Fixtures\Team;
use Tests\NafTestCase;

use function Naf\ORM\em;

class EntityManagerTest extends NafTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->clearFixtures();
    }

    public function testSavePersistsEntitiesAndPivot(): void
    {
        $player = new Player(['name' => 'Walker', 'age' => 28]);
        $team   = new Team(['name' => 'Striders']);
        $player->addTeam($team);
        $team->addPlayer($player);

        em()->save($player);

        $this->assertNotNull($player->getId());
        $this->assertNotNull($team->getId());
        $this->assertSame(1, (int) self::$pdo->query('SELECT COUNT(*) FROM players')->fetchColumn());
        $this->assertSame(1, (int) self::$pdo->query('SELECT COUNT(*) FROM teams')->fetchColumn());
        $this->assertSame(
            1,
            (int) self::$pdo->query('SELECT COUNT(*) FROM player_team_links')->fetchColumn(),
        );
    }

    public function testSavingTwiceDoesNotDuplicatePivot(): void
    {
        $player = new Player(['name' => 'Walker', 'age' => 28]);
        $team   = new Team(['name' => 'Striders']);
        $player->addTeam($team);
        $team->addPlayer($player);

        em()->save($player);

        em()->save($player);

        $this->assertSame(1, (int) self::$pdo->query('SELECT COUNT(*) FROM players')->fetchColumn());
        $this->assertSame(
            1,
            (int) self::$pdo->query('SELECT COUNT(*) FROM player_team_links')->fetchColumn(),
        );
        $this->assertSame(1, (int) self::$pdo->query('SELECT COUNT(*) FROM teams')->fetchColumn());
    }

    public function testPivotWriteDoesNotIgnoreForeignKeyViolation(): void
    {
        $player = new Player(['id' => 999_999, 'name' => 'Missing', 'age' => 28]);
        $team   = new Team(['name' => 'Existing']);
        $player->addTeam($team);
        $team->addPlayer($player);

        $this->expectException(DatabaseException::class);
        em()->save($player);
    }

    public function testSavingSameEntityAgainPersistsChanges(): void
    {
        $player = new Player(['name' => 'Walker', 'age' => 28]);

        em()->save($player);
        $player->setAge(99);
        em()->save($player);

        $stmt = self::$pdo->prepare('SELECT age FROM players WHERE id = :id');
        $stmt->execute(['id' => $player->getId()]);

        $this->assertSame(99, (int) $stmt->fetchColumn());
    }

    public function testSavePersistsOneToManyWithRequiredForeignKey(): void
    {
        $project = new Project(['name' => 'Release']);
        $task    = new Task(['title' => 'Review']);
        $project->addTask($task);

        em()->save($project);

        $this->assertNotNull($project->getId());
        $this->assertNotNull($task->getId());
        $this->assertSame($project->getId(), $task->getProjectId());
        $this->assertSame(1, (int) self::$pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn());
        $this->assertSame(1, (int) self::$pdo->query('SELECT COUNT(*) FROM tasks')->fetchColumn());
    }

    public function testSavePersistsManyToOneWhenChildIsTheRoot(): void
    {
        $project = new Project(['name' => 'Release']);
        $task    = new Task(['title' => 'Review']);
        $project->addTask($task);

        em()->save($task);

        $this->assertNotNull($project->getId());
        $this->assertNotNull($task->getId());
        $this->assertSame($project->getId(), $task->getProjectId());
        $this->assertSame(1, (int) self::$pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn());
        $this->assertSame(1, (int) self::$pdo->query('SELECT COUNT(*) FROM tasks')->fetchColumn());
    }

    public function testInjectedForeignKeyIsFlushedForAlreadyPersistedEntity(): void
    {
        $portfolio = new Portfolio(['name' => 'Roadmap']);
        $project   = new Project(['name' => 'ORM']);
        $milestone = new Milestone(['title' => 'Release']);

        $portfolio->addMilestone($milestone);
        $project->addMilestone($milestone);
        $portfolio->addProject($project);

        em()->save($portfolio);

        $stmt = self::$pdo->prepare(
            'SELECT portfolio_id, project_id FROM milestones WHERE id = :id',
        );
        $stmt->execute(['id' => $milestone->getId()]);
        $row = $stmt->fetch();

        $this->assertIsArray($row);
        $this->assertSame($portfolio->getId(), (int) $row['portfolio_id']);
        $this->assertSame($project->getId(), (int) $row['project_id']);
        $this->assertSame($portfolio->getId(), $milestone->getPortfolioId());
        $this->assertSame($project->getId(), $milestone->getProjectId());
    }

    public function testFailedSaveRestoresIdsAndCanBeRetried(): void
    {
        $project    = new Project(['name' => 'Release']);
        $firstTask  = new Task(['title' => 'Duplicate']);
        $secondTask = new Task(['title' => 'Duplicate']);
        $project->addTask($firstTask);
        $project->addTask($secondTask);

        try {
            em()->save($project);
            $this->fail('The duplicate task title should make the transaction fail.');
        } catch (DatabaseException) {
            $this->assertNull($project->getId());
            $this->assertNull($firstTask->getId());
            $this->assertNull($secondTask->getId());
            $this->assertNull($firstTask->getProjectId());
            $this->assertNull($secondTask->getProjectId());
            $this->assertSame(
                0,
                (int) self::$pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn(),
            );
            $this->assertSame(
                0,
                (int) self::$pdo->query('SELECT COUNT(*) FROM tasks')->fetchColumn(),
            );
        }

        $secondTask->setTitle('Unique');
        em()->save($project);

        $this->assertNotNull($project->getId());
        $this->assertNotNull($firstTask->getId());
        $this->assertNotNull($secondTask->getId());
        $this->assertSame(1, (int) self::$pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn());
        $this->assertSame(2, (int) self::$pdo->query('SELECT COUNT(*) FROM tasks')->fetchColumn());
    }

    public function testSaveUsesSavepointInsideExistingTransaction(): void
    {
        $manager                = em();
        $outerTransactionActive = false;

        try {
            $manager->begin();
            $outerTransactionActive = true;

            $player = new Player(['name' => 'Nested', 'age' => 42]);
            $manager->save($player);

            $this->assertTrue(self::$pdo->inTransaction());
            $this->assertSame(
                1,
                (int) self::$pdo->query("SELECT COUNT(*) FROM players WHERE name = 'Nested'")
                    ->fetchColumn(),
            );

            $manager->commit();
            $outerTransactionActive = false;
        } finally {
            if ($outerTransactionActive) {
                $manager->rollback();
            }
        }

        $this->assertFalse(self::$pdo->inTransaction());
    }

    public function testFailedNestedSaveKeepsOuterTransactionActive(): void
    {
        $manager                = em();
        $outerTransactionActive = false;

        try {
            $manager->begin();
            $outerTransactionActive = true;

            $team = new Team(['name' => 'Outer']);
            $manager->save($team);

            $project = new Project(['name' => 'Nested']);
            $project->addTask(new Task(['title' => 'Duplicate']));
            $project->addTask(new Task(['title' => 'Duplicate']));

            try {
                $manager->save($project);
                $this->fail('The duplicate task title should make the nested save fail.');
            } catch (DatabaseException) {
                $this->assertTrue(self::$pdo->inTransaction());
                $this->assertSame(
                    1,
                    (int) self::$pdo->query("SELECT COUNT(*) FROM teams WHERE name = 'Outer'")
                        ->fetchColumn(),
                );
                $this->assertSame(
                    0,
                    (int) self::$pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn(),
                );
            }

            $manager->commit();
            $outerTransactionActive = false;
        } finally {
            if ($outerTransactionActive) {
                $manager->rollback();
            }
        }

        $this->assertSame(
            1,
            (int) self::$pdo->query("SELECT COUNT(*) FROM teams WHERE name = 'Outer'")
                ->fetchColumn(),
        );
    }

    public function testClearThrowsWhenTransactionActive(): void
    {
        $this->expectException(RuntimeException::class);
        em()->begin();

        try {
            em()->clear();
        } finally {
            em()->rollback();
        }
    }
}
