<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Weline\Framework\Runtime\WlsRuntime;

/**
 * Deferred storefront FPC warmup must publish under the public edge authority
 * (public_origin, e.g. host:9555), not the Worker listener port (host:19655).
 */
final class WlsRuntimePublicOriginWarmupHostContractTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverBackup = [];

    /** @var array<string, string|false> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['WLS_INSTANCE', 'WLS_INSTANCE_NAME', 'WLS_PUBLIC_ORIGIN'] as $key) {
            $this->serverBackup[$key] = $_SERVER[$key] ?? null;
            $this->serverBackup['env:' . $key] = $_ENV[$key] ?? null;
            $this->envBackup[$key] = \getenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach (['WLS_INSTANCE', 'WLS_INSTANCE_NAME', 'WLS_PUBLIC_ORIGIN'] as $key) {
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
            $previous = $this->envBackup[$key] ?? false;
            if ($previous === false) {
                \putenv($key);
            } else {
                \putenv($key . '=' . $previous);
            }
        }
        parent::tearDown();
    }

    public function testSelectStorefrontWarmupHostPrefersPublicEdgePortOverWorkerPort(): void
    {
        $runtime = (new ReflectionClass(WlsRuntime::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(WlsRuntime::class, 'selectStorefrontWarmupHost');
        $method->setAccessible(true);

        self::assertSame(
            'p05113ef3.test.weline.com:9555',
            $method->invoke($runtime, [
                'p05113ef3.test.weline.com:9555',
                'p05113ef3.test.weline.com:19655',
                '127.0.0.1:19655',
            ])
        );
    }

    public function testResolveWorkerPublicOriginFallsBackToInstancePublicOrigin(): void
    {
        if (!\defined('BP')) {
            self::markTestSkipped('BP undefined');
        }

        $instanceFile = BP . 'var' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR
            . 'instances' . DIRECTORY_SEPARATOR . 'default.json';
        if (!\is_file($instanceFile)) {
            self::markTestSkipped('default instance metadata missing');
        }

        $data = \json_decode((string)\file_get_contents($instanceFile), true);
        $publicOrigin = \is_array($data) ? \trim((string)($data['public_origin'] ?? '')) : '';
        if ($publicOrigin === '' || !\str_contains($publicOrigin, '://')) {
            self::markTestSkipped('default instance has no public_origin');
        }

        \putenv('WLS_PUBLIC_ORIGIN');
        unset($_ENV['WLS_PUBLIC_ORIGIN'], $_SERVER['WLS_PUBLIC_ORIGIN']);
        $_SERVER['WLS_INSTANCE'] = 'default';
        $_ENV['WLS_INSTANCE'] = 'default';
        \putenv('WLS_INSTANCE=default');

        $runtime = (new ReflectionClass(WlsRuntime::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(WlsRuntime::class, 'resolveWorkerPublicOrigin');
        $method->setAccessible(true);
        $origin = $method->invoke($runtime);

        self::assertIsArray($origin);
        self::assertSame('https', $origin['scheme'] ?? null);
        self::assertStringContainsString('.test.weline.com:', (string)($origin['host'] ?? ''));
        self::assertStringNotContainsString(':19655', (string)($origin['host'] ?? ''));
        self::assertSame(
            \parse_url($publicOrigin, \PHP_URL_HOST) . ':' . \parse_url($publicOrigin, \PHP_URL_PORT),
            $origin['host']
        );
    }

    public function testProcessLocalDynamicWarmupHostsPreferPublicOriginAuthority(): void
    {
        if (!\defined('BP')) {
            self::markTestSkipped('BP undefined');
        }

        $instanceFile = BP . 'var' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR
            . 'instances' . DIRECTORY_SEPARATOR . 'default.json';
        if (!\is_file($instanceFile)) {
            self::markTestSkipped('default instance metadata missing');
        }

        $data = \json_decode((string)\file_get_contents($instanceFile), true);
        $publicOrigin = \is_array($data) ? \trim((string)($data['public_origin'] ?? '')) : '';
        $mainPort = \is_array($data) ? (int)($data['main_port'] ?? $data['port'] ?? 0) : 0;
        if ($publicOrigin === '' || $mainPort <= 0) {
            self::markTestSkipped('default instance missing public_origin/main_port');
        }
        $publicPort = (int)\parse_url($publicOrigin, \PHP_URL_PORT);
        if ($publicPort <= 0 || $publicPort === $mainPort) {
            self::markTestSkipped('public_origin port equals worker port; authority drift not observable');
        }

        \putenv('WLS_PUBLIC_ORIGIN');
        unset($_ENV['WLS_PUBLIC_ORIGIN'], $_SERVER['WLS_PUBLIC_ORIGIN']);
        $_SERVER['WLS_INSTANCE'] = 'default';
        $_ENV['WLS_INSTANCE'] = 'default';
        \putenv('WLS_INSTANCE=default');

        $runtime = (new ReflectionClass(WlsRuntime::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(WlsRuntime::class, 'resolveProcessLocalDynamicWarmupHosts');
        $method->setAccessible(true);
        $hosts = $method->invoke($runtime);

        self::assertSame(
            [\parse_url($publicOrigin, \PHP_URL_HOST) . ':' . $publicPort],
            $hosts
        );
        self::assertStringNotContainsString(':' . $mainPort, (string)($hosts[0] ?? ''));
    }

    public function testAdoptProofLogsIncompleteOrAdoptedStages(): void
    {
        $source = (string)\file_get_contents(
            (new ReflectionClass(WlsRuntime::class))->getFileName() ?: ''
        );

        self::assertStringContainsString("logDeferredStorefrontWarmupStage('adopted'", $source);
        self::assertStringContainsString("logDeferredStorefrontWarmupStage('incomplete'", $source);
        self::assertStringContainsString('homepage-not-ready', $source);
        self::assertStringContainsString("'public_origin'", $source);
    }
}
