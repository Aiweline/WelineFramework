<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Weline\Framework\Runtime\WlsRuntime;

/**
 * Fail-open homepage READY must still schedule deferred storefront critical
 * warmup so `/` (and catalog slots) can publish Process FPC before first HIT.
 */
final class WlsRuntimeFailOpenDeferredWarmupContractTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $envBackup = [];

    /** @var array<string, mixed> */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'WLS_WORKER_STOREFRONT_DEFERRED_WARMUP_ENABLED',
            'WLS_WORKER_READY_GATE_HOMEPAGE_FAIL_OPEN',
            'WLS_WORKER_STOREFRONT_DEFERRED_WARMUP_OWNER_WORKER_ID',
            'WLS_PROCESS_ROLE',
            'WLS_WORKER_ID',
        ] as $key) {
            $current = \getenv($key);
            $this->envBackup[$key] = $current === false ? false : (string) $current;
        }
        foreach (['WLS_PROCESS_ROLE', 'WLS_WORKER_ID'] as $key) {
            $this->serverBackup[$key] = $_SERVER[$key] ?? null;
            $this->serverBackup['env:' . $key] = $_ENV[$key] ?? null;
        }

        $_SERVER['WLS_PROCESS_ROLE'] = 'worker';
        $_ENV['WLS_PROCESS_ROLE'] = 'worker';
        \putenv('WLS_PROCESS_ROLE=worker');
        $_SERVER['WLS_WORKER_ID'] = '1';
        $_ENV['WLS_WORKER_ID'] = '1';
        \putenv('WLS_WORKER_ID=1');
        \putenv('WLS_WORKER_STOREFRONT_DEFERRED_WARMUP_OWNER_WORKER_ID=1');
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $value) {
            if ($value === false) {
                \putenv($key);
                unset($_ENV[$key]);
            } else {
                \putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
        }
        foreach (['WLS_PROCESS_ROLE', 'WLS_WORKER_ID'] as $key) {
            $server = $this->serverBackup[$key] ?? null;
            $env = $this->serverBackup['env:' . $key] ?? null;
            if ($server === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }
            if ($env === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $env;
            }
        }
        parent::tearDown();
    }

    public function testFailOpenForcesDeferredWarmupEvenWhenFlagDefaultOff(): void
    {
        \putenv('WLS_WORKER_READY_GATE_HOMEPAGE_FAIL_OPEN=1');
        \putenv('WLS_WORKER_STOREFRONT_DEFERRED_WARMUP_ENABLED=0');

        $runtime = new WlsRuntime();
        $this->setHomepageProof($runtime, ['hit' => false, 'reason' => 'homepage-fpc:deferred-after-ready:fail-open']);

        self::assertTrue($this->invokeShouldRun($runtime));
    }

    public function testStrictHitWithDeferredFlagOffDoesNotForceWarmup(): void
    {
        \putenv('WLS_WORKER_READY_GATE_HOMEPAGE_FAIL_OPEN=0');
        \putenv('WLS_WORKER_STOREFRONT_DEFERRED_WARMUP_ENABLED=0');

        $runtime = new WlsRuntime();
        $this->setHomepageProof($runtime, [
            'hit' => true,
            'fpc_status' => 'HIT',
            'source' => 'process',
            'full_uri' => 'https://example.test/',
            'reason' => 'strict-prime',
            'http_status' => 200,
        ]);

        self::assertFalse($this->invokeShouldRun($runtime));
    }

    public function testMissedProofForcesDeferredEvenWhenFailOpenDisabled(): void
    {
        \putenv('WLS_WORKER_READY_GATE_HOMEPAGE_FAIL_OPEN=0');
        \putenv('WLS_WORKER_STOREFRONT_DEFERRED_WARMUP_ENABLED=0');

        $runtime = new WlsRuntime();
        $this->setHomepageProof($runtime, ['hit' => false, 'reason' => 'missing']);

        self::assertTrue($this->invokeShouldRun($runtime));
    }

    private function invokeShouldRun(WlsRuntime $runtime): bool
    {
        $method = new ReflectionMethod(WlsRuntime::class, 'shouldRunDeferredStorefrontCriticalWarmup');
        $method->setAccessible(true);

        return (bool) $method->invoke($runtime);
    }

    /**
     * @param array<string, mixed> $proof
     */
    private function setHomepageProof(WlsRuntime $runtime, array $proof): void
    {
        $property = new ReflectionProperty(WlsRuntime::class, 'readyGateHomepageFpcProof');
        $property->setAccessible(true);
        $property->setValue($runtime, $proof);
    }
}
