<?php
declare(strict_types=1);
namespace Tests\Unit;
use Naf\ORM\Core\EntityManager;
use PDO;
use PHPUnit\Framework\TestCase;
final class TransactionOwnershipTest extends TestCase
{
    public function testInnerCommitDoesNotCommitTheForeignTransaction(): void
    {
        $pdo=new PDO('sqlite::memory:'); $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY)');
        $em=new EntityManager($pdo);
        $pdo->beginTransaction(); $pdo->exec('INSERT INTO items VALUES (1)');
        $em->begin(); $pdo->exec('INSERT INTO items VALUES (2)'); $em->commit();
        $this->assertTrue($pdo->inTransaction());
        $pdo->rollBack();
        $this->assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM items')->fetchColumn());
    }
    public function testInnerRollbackPreservesTheOuterWork(): void
    {
        $pdo=new PDO('sqlite::memory:'); $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY)');
        $em=new EntityManager($pdo); $em->begin(); $pdo->exec('INSERT INTO items VALUES (1)');
        $em->begin(); $pdo->exec('INSERT INTO items VALUES (2)'); $em->rollback(); $em->commit();
        $this->assertSame([1],$pdo->query('SELECT id FROM items')->fetchAll(PDO::FETCH_COLUMN));
        $this->assertFalse($pdo->inTransaction());
    }
    public function testIndependentManagersDoNotReleaseEachOthersSavepoints(): void
    {
        $pdo=new PDO('sqlite::memory:'); $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY)');
        $a=new EntityManager($pdo); $b=new EntityManager($pdo);
        $pdo->beginTransaction(); $a->begin(); $pdo->exec('INSERT INTO items VALUES (1)');
        $b->begin(); $pdo->exec('INSERT INTO items VALUES (2)'); $b->rollback(); $a->commit(); $pdo->commit();
        $this->assertSame([1],$pdo->query('SELECT id FROM items')->fetchAll(PDO::FETCH_COLUMN));
    }
}
