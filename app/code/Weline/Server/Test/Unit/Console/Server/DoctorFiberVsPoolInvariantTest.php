<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console\Server;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Doctor;

/**
 * pool_size must cover instance fiber.max_active (start fail-closed shares this).
 */
final class DoctorFiberVsPoolInvariantTest extends TestCase
{
    public function testOkWhenPoolCoversInstanceFiberMaxActive(): void
    {
        $result = Doctor::resolveFiberVsPoolFromConfig('wls-main-validate', [
            'db' => ['master' => ['pool_size' => 128]],
            'wls' => [
                'fiber' => ['max_active' => 12],
                'servers' => [
                    'wls-main-validate' => [
                        'fiber' => ['max_active' => 128],
                    ],
                ],
            ],
        ]);
        self::assertTrue($result['ok']);
        self::assertSame(128, $result['pool_size']);
        self::assertSame(128, $result['fiber_max_active']);
        self::assertNull($result['message']);
    }

    public function testFailWhenPoolBelowInstanceFiberMaxActive(): void
    {
        $result = Doctor::resolveFiberVsPoolFromConfig('wls-main-validate', [
            'db' => ['master' => ['pool_size' => 48]],
            'wls' => [
                'fiber' => ['max_active' => 12],
                'servers' => [
                    'wls-main-validate' => [
                        'fiber' => ['max_active' => 128],
                    ],
                ],
            ],
        ]);
        self::assertFalse($result['ok']);
        self::assertSame(48, $result['pool_size']);
        self::assertSame(128, $result['fiber_max_active']);
        self::assertNotNull($result['message']);
        self::assertStringContainsString('ConnectionPoolExhaustedException', (string)$result['message']);
    }
}
