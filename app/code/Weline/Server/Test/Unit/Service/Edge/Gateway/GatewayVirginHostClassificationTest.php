<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayClient;
use Weline\Server\Service\Edge\Gateway\GatewayHostManager;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;
use Weline\Server\Service\Edge\Gateway\GatewayPlatformServiceInstaller;
use Weline\Server\Service\Edge\Gateway\HostGatewayPackageManager;

final class GatewayVirginHostClassificationTest extends TestCase
{
    private string $root = '';

    /** @var array<string,string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        foreach ([
            'WLS_GATEWAY_TEST_MODE',
            'WLS_GATEWAY_HOME',
            'WLS_GATEWAY_LISTEN_HTTP',
            'WLS_GATEWAY_LISTEN_HTTPS',
        ] as $name) {
            $this->environment[$name] = \getenv($name);
        }
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-gateway-virgin-' . \bin2hex(\random_bytes(8));
        [$http, $https] = $this->releasedPorts();
        \putenv('WLS_GATEWAY_TEST_MODE=1');
        \putenv('WLS_GATEWAY_HOME=' . $this->root);
        \putenv('WLS_GATEWAY_LISTEN_HTTP=' . $http);
        \putenv('WLS_GATEWAY_LISTEN_HTTPS=' . $https);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        foreach ($this->environment as $name => $value) {
            \putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    public function testRegisteredForeignServiceBlocksAutoBootstrapWithoutCreatingHostRoot(): void
    {
        $manager = $this->managerWithRegistrationState('PRESENT');

        $result = $manager->prepare(
            ['ok' => false, 'ready' => false, 'state' => 'UNAVAILABLE'],
            self::deadline(),
        );

        self::assertSame('HOST_SERVICE_PRESENT', $result['state']);
        self::assertFalse(\file_exists($this->root));
    }

    public function testIndeterminateServiceRegistrationBlocksAutoBootstrapWithoutCreatingHostRoot(): void
    {
        $manager = $this->managerWithRegistrationState('UNKNOWN');

        $result = $manager->prepare(
            ['ok' => false, 'ready' => false, 'state' => 'UNAVAILABLE'],
            self::deadline(),
        );

        self::assertSame('REPAIR_REQUIRED', $result['state']);
        self::assertFalse(\file_exists($this->root));
    }

    public function testResidualHostIdentityCannotBeClassifiedAsVirgin(): void
    {
        $paths = new GatewayPaths();
        self::assertTrue(\mkdir($paths->trustDir(), 0700, true));
        self::assertNotFalse(\file_put_contents(
            $paths->hostIdFile(),
            \str_repeat('a', 64) . "\n",
        ));

        $result = $this->managerWithRegistrationState('ABSENT')->prepare(
            ['ok' => false, 'ready' => false, 'state' => 'UNAVAILABLE'],
            self::deadline(),
        );

        self::assertSame('REPAIR_REQUIRED', $result['state']);
        self::assertStringContainsString('residual', \strtolower($result['reason']));
    }

    public function testOnlyEmptyDirectoriesLogsAndRootOnlyLockScaffoldingRemainVirgin(): void
    {
        $paths = new GatewayPaths();
        self::assertTrue(\mkdir($paths->logDir(), 0700, true));
        self::assertTrue(\mkdir($paths->trustDir(), 0700, true));
        foreach (['package-bootstrap.lock', 'package-install.lock'] as $lock) {
            $path = $paths->trustDir() . DIRECTORY_SEPARATOR . $lock;
            self::assertNotFalse(\file_put_contents($path, ''));
            self::assertTrue(\chmod($path, 0600));
        }
        self::assertNotFalse(\file_put_contents(
            $paths->logDir() . DIRECTORY_SEPARATOR . 'bootstrap.log',
            "prior diagnostic\n",
        ));

        $result = $this->managerWithRegistrationState('ABSENT')->prepare(
            ['ok' => false, 'ready' => false, 'state' => 'UNAVAILABLE'],
            self::deadline(),
        );

        self::assertSame('INSTALL_REQUIRED', $result['state']);
        self::assertSame('default', $result['listen_profile']);
    }

    public function testFreshInstallerRefusesRegisteredServiceBeforeAnyHostWrite(): void
    {
        $paths = new GatewayPaths();
        $installer = new GatewayPlatformServiceInstaller(
            $paths,
            null,
            static fn (): array => [
                'state' => 'PRESENT',
                'reason' => 'A same-name foreign test service is registered.',
            ],
        );

        try {
            $installer->installDefinition('default', self::deadline());
            self::fail('A registered foreign service must block initial installation.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'HOST_SERVICE_PRESENT',
                $exception->getMessage(),
            );
        }
        self::assertFalse(\file_exists($this->root));
    }

    public function testInstallerRechecksRegistrationUnderInstallLockBeforeDefinitionWrite(): void
    {
        $paths = new GatewayPaths();
        $calls = 0;
        $installer = new GatewayPlatformServiceInstaller(
            $paths,
            null,
            static function () use (&$calls): array {
                ++$calls;
                return $calls === 1
                    ? ['state' => 'ABSENT', 'reason' => 'absent before lock']
                    : ['state' => 'PRESENT', 'reason' => 'registered during lock wait'];
            },
        );

        try {
            $installer->installDefinition('default', self::deadline());
            self::fail('The under-lock service registration race must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'HOST_SERVICE_PRESENT',
                $exception->getMessage(),
            );
        }
        self::assertSame(2, $calls);
        self::assertFalse(\file_exists($paths->serviceDefinitionFile()));
        self::assertFalse(\file_exists($paths->platformServiceMetadataFile()));
    }

    private function managerWithRegistrationState(string $state): GatewayHostManager
    {
        $paths = new GatewayPaths();
        $platform = new GatewayPlatformServiceInstaller(
            $paths,
            null,
            static fn (): array => [
                'state' => $state,
                'reason' => 'Injected read-only platform registration result.',
            ],
        );
        return new GatewayHostManager(
            $paths,
            new GatewayClient($paths),
            new HostGatewayPackageManager($paths, platform: $platform),
            $platform,
        );
    }

    /** @return array{0:int,1:int} */
    private function releasedPorts(): array
    {
        $ports = [];
        foreach ([0, 1] as $_) {
            $socket = \stream_socket_server(
                'tcp://127.0.0.1:0',
                $errno,
                $error,
                STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            );
            self::assertIsResource($socket, $error !== '' ? $error : (string)$errno);
            $address = \stream_socket_get_name($socket, false);
            self::assertIsString($address);
            $ports[] = (int)\substr($address, (int)\strrpos($address, ':') + 1);
            \fclose($socket);
        }
        return [$ports[0], $ports[1]];
    }

    private static function deadline(): float
    {
        return (\hrtime(true) / 1_000_000_000) + 5.0;
    }

    private function removeTree(string $path): void
    {
        if (!\is_dir($path) || \is_link($path)) {
            return;
        }
        foreach (\scandir($path) ?: [] as $entry) {
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
