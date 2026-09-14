<?php
declare(strict_types=1);
namespace Tests\Unit;
use Naf\ORM\Model\AbstractModel;
use Naf\ORM\Repository\AbstractRepository;
use Naf\ORM\Core\EntityManager;
use PDO;
use PHPUnit\Framework\TestCase;
final class NamedRecord extends AbstractModel
{
    protected string $value='';
    public function getTableName(bool $singular = false): string { return 'named_records_custom'; }
}
final class NamedRecordRepository extends AbstractRepository
{protected function getEntityClass(): string { return NamedRecord::class; }}
final class TableContractTest extends TestCase
{
    public function testPersistenceAndRepositoryHonorTheSameExplicitTableName(): void
    {
        $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $pdo->exec('CREATE TABLE named_records_custom(id INTEGER PRIMARY KEY AUTOINCREMENT,value TEXT NOT NULL)');$em=new EntityManager($pdo);
        $record=new NamedRecord(['value'=>'same contract']);$em->save($record);
        $read=(new NamedRecordRepository($pdo,$em))->findOneBy('value','same contract');
        self::assertNotNull($read);self::assertSame($record->getId(),$read->getId());
    }
}
