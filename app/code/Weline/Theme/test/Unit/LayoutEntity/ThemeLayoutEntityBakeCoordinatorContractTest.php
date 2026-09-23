<?php
declare(strict_types=1);
namespace Weline\Theme\Test\Unit\LayoutEntity;
use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator;
final class ThemeLayoutEntityBakeCoordinatorContractTest extends TestCase
{
    public function testResourceReceiptRequiresRealOutputAndUsesContentDigest(): void
    {
        $coordinator = (new \ReflectionClass(ThemeLayoutEntityBakeCoordinator::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($coordinator, 'resourceArtifactReceipt');
        $path = tempnam(sys_get_temp_dir(), 'theme-resource-receipt-');
        try {
            file_put_contents($path, '<section>solidified</section>');
            $receipt = $method->invoke($coordinator, 'page', $path);
            self::assertTrue($receipt['exists']);
            self::assertSame('page', $receipt['type']);
            self::assertSame(hash_file('sha256', $path), $receipt['artifact_id']);
            self::assertArrayNotHasKey('path', $receipt);
            unlink($path);
            $this->expectException(\RuntimeException::class);
            $method->invoke($coordinator, 'page', $path);
        } finally {
            if (is_file($path)) { unlink($path); }
        }
    }
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
