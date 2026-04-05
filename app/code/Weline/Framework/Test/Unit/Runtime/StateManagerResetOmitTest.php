<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\StateManager;

final class StateManagerResetOmitTest extends TestCase
{
    private static int $hit = 0;

    protected function setUp(): void
    {
        self::$hit = 0;
        StateManager::registerResetCallback('__unit_test_omit_probe__', function (): void {
            self::$hit++;
        });
    }

    protected function tearDown(): void
    {
        StateManager::unregisterResetCallback('__unit_test_omit_probe__');
        parent::tearDown();
    }

    public function testResetCanOmitNamedCallbacks(): void
    {
        StateManager::reset(['__unit_test_omit_probe__']);
        self::assertSame(0, self::$hit);

        StateManager::reset();
        self::assertSame(1, self::$hit);
    }
}
