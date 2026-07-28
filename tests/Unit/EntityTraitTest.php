<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Fixtures\Player;
use Tests\Fixtures\Team;

class EntityTraitTest extends TestCase
{
    public function testGetFieldsIgnoresRelationObjects(): void
    {
        $player = new Player([
            'name' => 'Scout',
            'age' => 7,
        ]);

        $player->addTeam(new Team(['name' => 'Red']));

        $fields = $player->getFields();

        $this->assertSame([
            'name' => 'Scout',
            'age' => 7,
        ], $fields);
    }

    public function testGetRelationsIncludesTeamsArray(): void
    {
        $player = new Player([
            'name' => 'Scout',
            'age' => 7,
        ]);

        $team = new Team(['name' => 'Red']);
        $player->addTeam($team);

        $relations = $player->getRelations();

        $this->assertArrayHasKey('teams', $relations);
        $this->assertSame([$team], $relations['teams']);
    }

    public function testReadingFieldsAndRelationsDoesNotTriggerDeprecations(): void
    {
        $deprecations = [];
        set_error_handler(static function (int $severity, string $message) use (&$deprecations): bool {
            if (!in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
                return false;
            }

            $deprecations[] = $message;

            return true;
        });

        try {
            $player = new Player([
                'name' => 'Scout',
                'age' => 7,
            ]);
            $player->addTeam(new Team(['name' => 'Red']));

            $player->getFields();
            $player->getRelations();
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $deprecations);
    }
}
