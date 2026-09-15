<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Fixtures\DummyRepository;

use function Naf\ORM\repo;

class RepoHelperTest extends TestCase
{
    public function testRepoReturnsRepositoryFromFactory(): void
    {
        $repository = repo(DummyRepository::class);
        $this->assertInstanceOf(DummyRepository::class, $repository);
    }

    public function testRepoReturnsSameInstance(): void
    {
        $first  = repo(DummyRepository::class);
        $second = repo(DummyRepository::class);
        $this->assertSame($first, $second);
    }
}
