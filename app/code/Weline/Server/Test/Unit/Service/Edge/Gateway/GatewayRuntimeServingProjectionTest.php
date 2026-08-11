<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Framework\System\Process\Processer;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;
use Weline\Server\Service\Edge\Gateway\GatewayPortLeaseAllocator;
use Weline\Server\Service\Edge\Gateway\GatewayRegistrationBuilder;
use Weline\Server\Service\Edge\Gateway\GatewayRuntimeServingProjection;
use Weline\Server\Service\Edge\Gateway\GatewayStartupRuntimeView;
use Weline\Server\Service\Edge\Gateway\ProjectIdentityStore;
use Weline\Server\Service\MasterLeaseRuntimeIdentity;
use Weline\Server\Service\Runtime\DirectSharedListener;

final class GatewayRuntimeServingProjectionTest extends TestCase
{
    private string $root = '';
    private string $processName = '';
    private string $originalProcessTitle = '';
    private string|false $originalStateHome = false;
    private ?DirectSharedListener $listener = null;

    protected function setUp(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Transferred listener identity test is POSIX-only.');
        }
        $base = \PHP_OS_FAMILY === 'Darwin' ? '/tmp' : \sys_get_temp_dir();
        $path = $base . DIRECTORY_SEPARATOR . 'wls-serving-projection-'
            . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($path, 0700, true));
        $canonical = \realpath($path);
        self::assertIsString($canonical);
        $this->root = $canonical;
        $this->originalStateHome = \getenv('WLS_EDGE_STATE_HOME');
        self::assertTrue(\putenv('WLS_EDGE_STATE_HOME=' . $this->root . '/host'));

        $this->processName = 'wls-serving-projection-' . \bin2hex(\random_bytes(4));
        if (\function_exists('cli_get_process_title')) {
            $this->originalProcessTitle = (string)@\cli_get_process_title();
        }
        if (\function_exists('cli_set_process_title')) {
            @\cli_set_process_title($this->processName);
        }
        Processer::setPid('--name=' . $this->processName, \getmypid());
    }

    protected function tearDown(): void
    {
        $this->listener?->close();
        Processer::removePidFile('--name=' . $this->processName);
        if (\function_exists('cli_set_process_title')) {
            @\cli_set_process_title($this->originalProcessTitle);
        }
        if ($this->originalStateHome === false) {
            \putenv('WLS_EDGE_STATE_HOME');
        } else {
            \putenv('WLS_EDGE_STATE_HOME=' . $this->originalStateHome);
        }
        $this->removeTree($this->root);
    }

    public function testInitialAutoFallbackUsesItsLivePlainInstanceLease(): void
    {
        $endpoint = $this->initialAutoFallbackEndpoint();

        $serving = GatewayRuntimeServingProjection::fallbackServingEndpoint($endpoint);

        self::assertIsArray($serving);
        self::assertSame($endpoint['port'], $serving['port'] ?? null);
        self::assertSame('127.0.0.1', $serving['bind_host'] ?? null);
        self::assertSame('127.0.0.1', $serving['connect_host'] ?? null);
        self::assertSame('shop.example.test', $serving['authority_host'] ?? null);
        self::assertSame($endpoint['public_origin'], $serving['origin'] ?? null);
        self::assertFalse($serving['https'] ?? true);
        $view = GatewayStartupRuntimeView::resolve($endpoint);
        self::assertSame(GatewayStartupRuntimeView::SOURCE_FALLBACK_WLS, $view['source']);
        self::assertTrue($view['public_proven']);
    }

    public function testExpiredProjectionDeadlineFailsClosedWithoutLeaseReadFallback(): void
    {
        $endpoint = $this->initialAutoFallbackEndpoint();
        $expired = (\hrtime(true) / 1_000_000_000) - 1.0;

        self::assertFalse(
            GatewayRuntimeServingProjection::fallbackWlsIsServing(
                $endpoint,
                $expired,
            ),
        );
        self::assertNull(
            GatewayRuntimeServingProjection::fallbackServingEndpoint(
                $endpoint,
                $expired,
            ),
        );
        self::assertNull(
            GatewayRuntimeServingProjection::fallbackServingObservation(
                $endpoint,
                $expired,
            ),
        );
        self::assertNull(
            GatewayRuntimeServingProjection::explicitPureWlsServingEndpoint(
                $endpoint,
                $expired,
            ),
        );
    }

    public function testLeaseProjectionInjectsOneAbsoluteDeadlineIntoEveryAllocator(): void
    {
        $source = (string)\file_get_contents(
            (string)(new \ReflectionClass(
                GatewayRuntimeServingProjection::class,
            ))->getFileName(),
        );

        self::assertStringContainsString('READ_BUDGET_SECONDS = 1.0', $source);
        self::assertSame(
            2,
            \substr_count(
                $source,
                'operationDeadlineMonotonic: $deadlineMonotonic',
            ),
        );
        self::assertStringNotContainsString(
            'new GatewayPortLeaseAllocator();',
            $source,
        );
    }

    public function testPlainInstanceLeaseCannotCrossFallbackRolesOrModes(): void
    {
        $endpoint = $this->initialAutoFallbackEndpoint();

        $requestedGateway = $endpoint;
        $requestedGateway['gateway']['requested_mode'] = 'gateway';
        self::assertNull(
            GatewayRuntimeServingProjection::fallbackServingEndpoint($requestedGateway),
        );

        $requestedWls = $endpoint;
        $requestedWls['gateway']['requested_mode'] = 'wls';
        self::assertNull(
            GatewayRuntimeServingProjection::fallbackServingEndpoint($requestedWls),
        );

        $effectiveGateway = $endpoint;
        $effectiveGateway['gateway']['mode'] = 'gateway';
        self::assertNull(
            GatewayRuntimeServingProjection::fallbackServingEndpoint($effectiveGateway),
        );

        $servingGateway = $endpoint;
        $servingGateway['gateway']['serving_mode'] = 'gateway';
        self::assertNull(
            GatewayRuntimeServingProjection::fallbackServingEndpoint($servingGateway),
        );

        $supplementalProof = $endpoint;
        $supplementalProof['gateway']['fallback_lease_proof'] = ['schema_version' => 2];
        self::assertNull(
            GatewayRuntimeServingProjection::fallbackServingEndpoint($supplementalProof),
        );

        $supplementalPort = $endpoint;
        $supplementalPort['gateway']['public_https'] = (int)$endpoint['port'];
        self::assertNull(
            GatewayRuntimeServingProjection::fallbackServingEndpoint($supplementalPort),
        );
    }

    public function testDrainingSupplementalLeaseIsNeverAcceptedAsServing(): void
    {
        $method = new \ReflectionMethod(
            GatewayRuntimeServingProjection::class,
            'fallbackLeaseProofMatches',
        );
        $lines = \file($method->getFileName());
        self::assertIsArray($lines);
        $source = \implode('', \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        self::assertStringContainsString(
            "!\\hash_equals('ACTIVE', (string)(\$proof['state'] ?? ''))",
            $source,
        );
        self::assertStringNotContainsString("['ACTIVE', 'DRAINING']", $source);
    }

    /** @return array<string,mixed> */
    private function initialAutoFallbackEndpoint(): array
    {
        $instanceId = 'projection-' . \bin2hex(\random_bytes(4));
        $allocator = new GatewayPortLeaseAllocator();
        $this->listener = new DirectSharedListener();
        $lease = $allocator->reserveBound(
            $instanceId,
            function (int $port): bool {
                $this->listener?->acquire('127.0.0.1', $port);
                return true;
            },
        );
        $masterLaunchId = \bin2hex(\random_bytes(16));
        $workerLaunchId = \bin2hex(\random_bytes(16));
        $identity = (new MasterLeaseRuntimeIdentity())->captureProcessIdentity(\getmypid());
        $allocator->prepareTransfer(
            $instanceId,
            (string)$lease['lease_id'],
            '127.0.0.1',
            (int)$lease['port'],
            $masterLaunchId,
        );
        $lease = $allocator->confirmTransferred(
            $instanceId,
            (int)$lease['port'],
            \getmypid(),
            $workerLaunchId,
            (string)$lease['lease_id'],
            '127.0.0.1',
            $this->processName,
            $masterLaunchId,
            $identity['birth'],
            $identity['pid_namespace_id'],
        );

        return [
            'edge_adapter' => 'wls',
            'edge_mode' => 'wls',
            'port' => (int)$lease['port'],
            'ssl_enabled' => false,
            'public_origin' => 'http://shop.example.test:' . (int)$lease['port'],
            'master_pid' => \getmypid(),
            'master_epoch' => 41,
            'gateway' => [
                'mode' => 'wls',
                'serving_mode' => 'fallback_wls',
                'requested_mode' => 'auto',
                'project_uuid' => (new ProjectIdentityStore())->projectUuid(),
                'instance_id' => $instanceId,
                'launch_id' => $masterLaunchId,
                'instance_generation' => 1,
                'backend_identity_schema' =>
                    GatewayRegistrationBuilder::BACKEND_IDENTITY_SCHEMA,
                'protocol' => GatewayPaths::PROTOCOL,
                'fallback_state' => 'DEGRADED_WLS',
                'degraded_reason' => 'PORT_TAKEN',
                'public_http' => 0,
                'public_https' => 0,
                'public_lease' => $lease,
            ],
        ];
    }

    private function removeTree(string $root): void
    {
        if (!\is_dir($root) || \is_link($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $item->isDir() && !$item->isLink() ? @\rmdir($path) : @\unlink($path);
        }
        @\rmdir($root);
    }
}
