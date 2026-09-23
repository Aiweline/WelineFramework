<?php
declare(strict_types=1);
namespace Weline\Theme\Test\Unit\LayoutEntity;
use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator;
final class ThemeLayoutEntityBakeCoordinatorContractTest extends TestCase
{
    public function testConfigurationCommandsDoNotRequireStructureGeneration(): void
    {
        $coordinator = (new \ReflectionClass(ThemeLayoutEntityBakeCoordinator::class))->newInstanceWithoutConstructor();
        self::assertFalse($coordinator->commandsAreStructural([
            ['op' => 'SET', 'path' => '/nodes/example/config/title', 'value' => 'updated'],
        ]));
        foreach (['slot_id', 'sort_order', 'is_active', 'area'] as $field) {
            self::assertTrue($coordinator->commandsAreStructural([
                ['op' => 'SET', 'path' => '/nodes/example/' . $field, 'value' => 'changed'],
            ]));
        }
        foreach (['ADD_NODE', 'REMOVE_NODE', 'MOVE_NODE'] as $op) {
            self::assertTrue($coordinator->commandsAreStructural([['op' => $op]]));
        }
    }
}
