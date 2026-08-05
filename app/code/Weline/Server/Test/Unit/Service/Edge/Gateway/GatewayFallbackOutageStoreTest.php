<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayFallbackOutageStore;

final class GatewayFallbackOutageStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-gateway-outage-' . \bin2hex(\random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach ((array)@\glob($this->directory . DIRECTORY_SEPARATOR . '*') as $file) {
            if (\is_string($file) && \is_file($file) && !\is_link($file)) {
                @\unlink($file);
            }
        }
        @\rmdir($this->directory);
        parent::tearDown();
    }

    public function testSameAgentOutageRestoresOriginalMonotonicWindow(): void
    {
        $store = new GatewayFallbackOutageStore($this->directory);

        $launch = \str_repeat('a', 32);
        $outage = \str_repeat('1', 32);
        self::assertSame([
            'down_since_monotonic' => 500.0,
            'elapsed_seconds' => 0.0,
            'restored' => false,
        ], $store->markDown(
            'project-a', 41, 7, $launch, $outage, 500.0,
        ));
        self::assertSame([
            'down_since_monotonic' => 500.0,
            'elapsed_seconds' => 89.0,
            'restored' => true,
        ], $store->markDown(
            'project-a', 41, 7, $launch, $outage, 589.0,
        ));
    }

    public function testNewMasterStartsANewOutageWindowAndHealthyStateClearsIt(): void
    {
        $store = new GatewayFallbackOutageStore($this->directory);
        self::assertSame(500.0, $store->markDown(
            'project-a', 41, 7, \str_repeat('a', 32),
            \str_repeat('1', 32), 500.0,
        )['down_since_monotonic']);
        self::assertSame(589.0, $store->markDown(
            'project-a', 42, 8, \str_repeat('b', 32),
            \str_repeat('2', 32), 589.0,
        )['down_since_monotonic']);

        $store->clear('project-a');

        self::assertSame(600.0, $store->markDown(
            'project-a', 42, 8, \str_repeat('b', 32),
            \str_repeat('3', 32), 600.0,
        )['down_since_monotonic']);
    }

    public function testAgentRestartAndNewOutageCannotInheritPersistedWindow(): void
    {
        $store = new GatewayFallbackOutageStore($this->directory);
        $launch = \str_repeat('a', 32);
        self::assertSame(500.0, $store->markDown(
            'project-a', 41, 7, $launch, \str_repeat('1', 32), 500.0,
        )['down_since_monotonic']);

        $restarted = $store->markDown(
            'project-a', 41, 7, $launch, \str_repeat('2', 32), 589.0,
        );
        self::assertSame(589.0, $restarted['down_since_monotonic']);
        self::assertSame(0.0, $restarted['elapsed_seconds']);
        self::assertFalse($restarted['restored']);

        $clockRegression = $store->markDown(
            'project-a', 41, 7, $launch, \str_repeat('2', 32), 10.0,
        );
        self::assertSame(10.0, $clockRegression['down_since_monotonic']);
        self::assertFalse($clockRegression['restored']);
    }
}
