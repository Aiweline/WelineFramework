<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayHostBootIdentity;
use Weline\Server\Service\Edge\Gateway\GatewayHostManager;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;
use Weline\Server\Service\Edge\Gateway\GatewayPortLeaseAllocator;
use Weline\Server\Service\Edge\Gateway\GatewayStartupDecision;
use Weline\Server\Service\Edge\Gateway\ProjectIdentityStore;

final class GatewayStartupDecisionIsolationTest extends TestCase
{
    private string $root = '';

    /** @var array<string,string|false> */
    private array $previousEnvironment = [];

    /** @var list<resource> */
    private array $publicListeners = [];

    protected function setUp(): void
    {
        foreach ([
            'WLS_GATEWAY_TEST_MODE',
            'WLS_GATEWAY_HOME',
            'WLS_GATEWAY_LISTEN_HTTP',
            'WLS_GATEWAY_LISTEN_HTTPS',
        ] as $name) {
            $this->previousEnvironment[$name] = \getenv($name);
        }
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-startup-decision-' . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->root, 0700, true));

        $http = $this->listenOnEphemeralPort();
        $https = $this->listenOnEphemeralPort();
        $this->publicListeners = [$http['socket'], $https['socket']];
        \putenv('WLS_GATEWAY_TEST_MODE=1');
        \putenv('WLS_GATEWAY_HOME=' . $this->root . DIRECTORY_SEPARATOR . 'gateway');
        \putenv('WLS_GATEWAY_LISTEN_HTTP=' . $http['port']);
        \putenv('WLS_GATEWAY_LISTEN_HTTPS=' . $https['port']);
    }

    protected function tearDown(): void
    {
        foreach ($this->publicListeners as $listener) {
            if (\is_resource($listener)) {
                @\fclose($listener);
            }
        }
        foreach ($this->previousEnvironment as $name => $value) {
            $value === false ? \putenv($name) : \putenv($name . '=' . $value);
        }
        $this->removeTree($this->root);
    }

    public function testPureWlsAndLegacyNeverOpenTheHostGatewayPath(): void
    {
        $startup = $this->startupDecision();
        $wls = $startup->decide(
            GatewayStartupDecision::MODE_WLS,
            'default',
            false,
            reserveListener: false,
        );
        self::assertSame(GatewayStartupDecision::MODE_WLS, $wls->mode);
        self::assertSame([], $wls->gateway);

        $legacy = $startup->decide(
            GatewayStartupDecision::MODE_LEGACY,
            'default',
            false,
            reserveListener: false,
        );
        self::assertSame(GatewayStartupDecision::MODE_LEGACY, $legacy->mode);
        self::assertSame([], $legacy->gateway);
        self::assertDirectoryDoesNotExist(
            $this->root . DIRECTORY_SEPARATOR . 'gateway',
        );
    }

    public function testUnknownPublicOwnerIsOnlyReadAndRemainsListening(): void
    {
        $manager = new GatewayHostManager(paths: new GatewayPaths());
        $status = $manager->prepare([
            'ok' => false,
            'ready' => false,
            'reason' => 'No enrolled WLS 2.0 control endpoint.',
        ]);

        self::assertFalse($status['ok']);
        self::assertSame('PORT_TAKEN', $status['state']);
        self::assertSame('unknown', $status['owner']);
        self::assertStringContainsString(
            'will not stop or modify it',
            (string)$status['reason'],
        );
        foreach ($this->publicListeners as $listener) {
            self::assertIsResource($listener);
            $address = \stream_socket_get_name($listener, false);
            self::assertIsString($address);
            $client = @\stream_socket_client('tcp://' . $address, $errno, $error, 0.5);
            self::assertIsResource($client, $error !== '' ? $error : (string)$errno);
            @\fclose($client);
        }
        self::assertDirectoryDoesNotExist(
            $this->root . DIRECTORY_SEPARATOR . 'gateway',
        );
    }

    private function startupDecision(): GatewayStartupDecision
    {
        $projects = new ProjectIdentityStore(
            (string)BP,
            $this->root . DIRECTORY_SEPARATOR . 'edge-state',
            $this->root . DIRECTORY_SEPARATOR . 'legacy-generation.json',
        );
        return new GatewayStartupDecision(
            new GatewayHostManager(paths: new GatewayPaths()),
            new GatewayPortLeaseAllocator(
                $projects,
                $this->root . DIRECTORY_SEPARATOR . 'leases',
                GatewayHostBootIdentity::current(),
            ),
        );
    }

    /** @return array{socket:resource,port:int} */
    private function listenOnEphemeralPort(): array
    {
        $socket = \stream_socket_server(
            'tcp://127.0.0.1:0',
            $errno,
            $error,
            \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
        );
        self::assertIsResource($socket, $error !== '' ? $error : (string)$errno);
        $address = \stream_socket_get_name($socket, false);
        self::assertIsString($address);
        $separator = \strrpos($address, ':');
        self::assertNotFalse($separator);
        $port = (int)\substr($address, $separator + 1);
        self::assertGreaterThan(1024, $port);
        return ['socket' => $socket, 'port' => $port];
    }

    private function removeTree(string $path): void
    {
        if ($path === '' || !\is_dir($path)) {
            return;
        }
        $entries = \scandir($path);
        if (!\is_array($entries)) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $target = $path . DIRECTORY_SEPARATOR . $entry;
            if (\is_dir($target) && !\is_link($target)) {
                $this->removeTree($target);
            } else {
                @\unlink($target);
            }
        }
        @\rmdir($path);
    }
}
