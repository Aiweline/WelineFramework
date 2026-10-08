<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Setup\Lock;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Exception;
use Weline\Framework\Setup\Lock\SoakExclusiveGate;

final class SoakExclusiveGateTest extends TestCase
{
    protected function tearDown(): void
    {
        SoakExclusiveGate::release();
        parent::tearDown();
    }

    public function testMissingLockIsInactive(): void
    {
        SoakExclusiveGate::release();
        $state = SoakExclusiveGate::inspect();
        self::assertFalse($state['active']);
        self::assertSame('missing', $state['reason']);
    }

    public function testFreshLockBlocksMaintenanceFlip(): void
    {
        $path = SoakExclusiveGate::acquire([
            'owner' => 'unit-test',
            'out' => 'soak-unit',
        ]);
        self::assertFileExists($path);
        self::assertTrue(SoakExclusiveGate::isActive());

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/soak|排他|maintenance:enable|拒绝/u');
        SoakExclusiveGate::assertClearForMaintenanceFlip('maintenance:enable');
    }

    public function testStaleLockDoesNotBlock(): void
    {
        $path = SoakExclusiveGate::acquire(['owner' => 'stale-unit']);
        $past = time() - 120;
        touch($path, $past);
        clearstatcache(true, $path);

        $state = SoakExclusiveGate::inspect(30);
        self::assertFalse($state['active']);
        self::assertSame('stale', $state['reason']);
        SoakExclusiveGate::assertClearForMaintenanceFlip('setup:upgrade');
    }
}
