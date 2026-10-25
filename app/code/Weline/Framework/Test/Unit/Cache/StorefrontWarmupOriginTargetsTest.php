<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Service\StorefrontWarmup\StorefrontWarmupHttpClient;

/**
 * Storefront warmup must never treat the WLS Worker listen port as a
 * browser-reachable origin. Warming https://project.test.weline.com:9555/
 * bypasses managed Nginx (the host resolves to loopback via /etc/hosts), so the
 * rendered document — and the published html-disk leaf — bakes the Worker port
 * into canonical / hreflang / storefront links.
 */
final class StorefrontWarmupOriginTargetsTest extends TestCase
{
    private string $instanceFile = '';

    private array $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!\defined('BP')) {
            \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);
        }
        if (!\defined('DS')) {
            \define('DS', \DIRECTORY_SEPARATOR);
        }
        $this->instanceFile = BP . 'var' . DS . 'server' . DS . 'instances' . DS . 'default.json';
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        @\unlink($this->instanceFile);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $instance
     */
    private function writeInstance(array $instance): void
    {
        $dir = \dirname($this->instanceFile);
        if (!\is_dir($dir)) {
            \mkdir($dir, 0775, true);
        }
        \file_put_contents($this->instanceFile, (string)\json_encode($instance));
    }

    /**
     * @return list<array{scheme:string,connect:string,port:int,host:string}>
     */
    private function targets(): array
    {
        $client = new StorefrontWarmupHttpClient();
        $method = new \ReflectionMethod($client, 'resolveTargets');
        $method->setAccessible(true);

        /** @var list<array{scheme:string,connect:string,port:int,host:string}> $targets */
        $targets = $method->invoke($client);

        return $targets;
    }

    public function testWorkerListenPortOriginIsNotAWarmupTarget(): void
    {
        \putenv('WLS_PORT=9555');
        $_SERVER['WLS_PORT'] = '9555';
        $this->writeInstance([
            'public_origin' => 'https://p05113ef3.test.weline.com:9555',
            'public_host' => 'p05113ef3.test.weline.com',
            'worker_port' => 9555,
            'ssl_enabled' => true,
        ]);

        $targets = $this->targets();

        foreach ($targets as $target) {
            self::assertFalse(
                \str_contains($target['host'], ':9555'),
                'No warmup target may carry the Worker listen port in its Host header '
                . '(a public Host + listen port makes the Worker bake :9555 into '
                . 'canonical/hreflang/storefront links and publish it into html-disk): '
                . \json_encode($target),
            );
        }
        // The public Host (443) target must survive so warming still has an edge path.
        self::assertTrue(
            $this->hasConnectTarget($targets, 'p05113ef3.test.weline.com', 443),
            'public_host target on 443 must remain: ' . \json_encode($targets),
        );
        // The loopback Worker target must also survive (origin render path).
        self::assertTrue(
            $this->hasConnectTarget($targets, '127.0.0.1', 9555),
            'loopback Worker target must remain: ' . \json_encode($targets),
        );
    }

    public function testGenuinePublicNonDefaultPortOriginIsKept(): void
    {
        \putenv('WLS_PORT=9555');
        $_SERVER['WLS_PORT'] = '9555';
        $this->writeInstance([
            'public_origin' => 'https://edge.example.test:8443',
            'public_host' => 'edge.example.test',
            'worker_port' => 9555,
            'ssl_enabled' => true,
        ]);

        $targets = $this->targets();

        self::assertTrue(
            $this->hasConnectTarget($targets, 'edge.example.test', 8443),
            'A real public non-default port origin must stay a warmup target: '
            . \json_encode($targets),
        );
    }

    public function testPortlessPublicOriginIsKept(): void
    {
        \putenv('WLS_PORT=9555');
        $_SERVER['WLS_PORT'] = '9555';
        $this->writeInstance([
            'public_origin' => 'https://p05113ef3.test.weline.com',
            'public_host' => 'p05113ef3.test.weline.com',
            'worker_port' => 9555,
            'ssl_enabled' => true,
        ]);

        $targets = $this->targets();

        self::assertTrue(
            $this->hasConnectTarget($targets, 'p05113ef3.test.weline.com', 443),
            'Portless public_origin must stay a warmup target: ' . \json_encode($targets),
        );
    }

    /**
     * @param list<array{scheme:string,connect:string,port:int,host:string}> $targets
     */
    private function hasConnectTarget(array $targets, string $connect, int $port): bool
    {
        foreach ($targets as $target) {
            if ($target['connect'] === $connect && $target['port'] === $port) {
                return true;
            }
        }

        return false;
    }
}
