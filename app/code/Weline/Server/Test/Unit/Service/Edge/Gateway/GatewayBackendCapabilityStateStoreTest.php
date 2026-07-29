<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayBackendCapabilityStateStore;
use Weline\Server\Service\Edge\Gateway\GatewayClient;

final class GatewayBackendCapabilityStateStoreTest extends TestCase
{
    private string $root = '';

    protected function tearDown(): void
    {
        if ($this->root !== '') {
            $this->removeTree($this->root);
        }
    }

    public function testSharedSessionRecoveryRequiresProjectWideHealthyWindow(): void
    {
        $now = 1000;
        $file = $this->stateFile();
        $store = new GatewayBackendCapabilityStateStore(
            $file,
            static function () use (&$now): int {
                return $now;
            },
            30,
        );
        $healthy = $this->observation('shared_session', 'authenticated_session_runtime');

        $first = $store->stabilize($healthy);
        self::assertSame('isolated', $first['mode']);
        self::assertSame('shared_session_recovery_pending', $first['evidence']['reason']);
        $firstState = (string)\file_get_contents($file);

        $now = 1029;
        $secondProcess = new GatewayBackendCapabilityStateStore(
            $file,
            static function () use (&$now): int {
                return $now;
            },
            30,
        );
        self::assertSame('isolated', $secondProcess->stabilize($healthy)['mode']);
        self::assertSame(
            $firstState,
            (string)\file_get_contents($file),
            'An unchanged pending observation must not rewrite shared project state.',
        );

        $now = 1030;
        $promoted = $secondProcess->stabilize($healthy);
        self::assertSame('shared_session', $promoted['mode']);
        self::assertSame(
            $healthy['evidence_digest'],
            $promoted['evidence_digest'],
        );
    }

    public function testFailureDemotesImmediatelyAndRestartsRecoveryWindow(): void
    {
        $now = 2000;
        $store = new GatewayBackendCapabilityStateStore(
            $this->stateFile(),
            static function () use (&$now): int {
                return $now;
            },
            10,
        );
        $healthy = $this->observation('shared_session', 'authenticated_session_runtime');
        $store->stabilize($healthy);
        $now = 2010;
        self::assertSame('shared_session', $store->stabilize($healthy)['mode']);

        $failed = $this->observation('isolated', 'session_runtime_unhealthy');
        $now = 2011;
        self::assertSame('isolated', $store->stabilize($failed)['mode']);
        $now = 2020;
        self::assertSame('isolated', $store->stabilize($healthy)['mode']);
        $now = 2030;
        self::assertSame('shared_session', $store->stabilize($healthy)['mode']);
    }

    public function testStatelessRuntimeDeclarationIsStableWithoutHealthRecoveryDelay(): void
    {
        $now = 2500;
        $file = $this->stateFile();
        $store = new GatewayBackendCapabilityStateStore(
            $file,
            static function () use (&$now): int {
                return $now;
            },
            30,
        );
        $stateless = $this->observation('stateless', 'declared_stateless_runtime');

        $first = $store->stabilize($stateless);
        self::assertSame('stateless', $first['mode']);
        self::assertFileDoesNotExist(
            $file,
            'Stateless is instance-local proof and must not create project recovery state.',
        );
        $now = 2600;
        self::assertSame('stateless', $store->stabilize($stateless)['mode']);
        self::assertFileDoesNotExist($file);
    }

    public function testDifferentStatelessInstanceGenerationsDoNotRewriteProjectState(): void
    {
        $now = 2700;
        $file = $this->stateFile();
        $store = new GatewayBackendCapabilityStateStore(
            $file,
            static function () use (&$now): int {
                return $now;
            },
            30,
        );
        $first = $this->observation('stateless', 'declared_stateless_runtime');
        $second = $first;
        $second['evidence']['instance_generation'] = 2;
        $second['evidence_digest'] = \hash(
            'sha256',
            GatewayClient::canonicalJson($second['evidence']),
        );

        self::assertSame('stateless', $store->stabilize($first)['mode']);
        self::assertFileDoesNotExist($file);
        $now = 2710;
        $result = $store->stabilize($second);

        self::assertSame('stateless', $result['mode']);
        self::assertSame($second['evidence_digest'], $result['evidence_digest']);
        self::assertFileDoesNotExist(
            $file,
            'Instance-local stateless generations must never write project recovery state.',
        );
    }

    public function testCorruptDerivedStateCannotSkipRecoveryQualification(): void
    {
        $now = 3000;
        $file = $this->stateFile();
        $store = new GatewayBackendCapabilityStateStore(
            $file,
            static function () use (&$now): int {
                return $now;
            },
            10,
        );
        $healthy = $this->observation('shared_session', 'authenticated_session_runtime');
        self::assertSame('isolated', $store->stabilize($healthy)['mode']);
        self::assertNotFalse(\file_put_contents($file, '{corrupt'));

        $now = 3040;
        $afterCorruption = $store->stabilize($healthy);

        self::assertSame('isolated', $afterCorruption['mode']);
        self::assertSame('shared_session_recovery_pending', $afterCorruption['evidence']['reason']);
        self::assertNotEmpty(\glob($file . '.corrupt-*') ?: []);
    }

    /** @return array<string,mixed> */
    private function observation(string $mode, string $reason): array
    {
        $evidence = $mode === 'stateless'
            ? [
                'schema' => 'wls-stateless-capability/1',
                'runtime_source' => 'project_endpoint',
                'runtime_declared' => true,
                'instance_generation' => 1,
                'reason' => $reason,
            ]
            : [
                'schema' => 'wls-session-capability/1',
                'storage' => 'wls',
                'runtime_source' => 'project_shared_state',
                'runtime_registered' => true,
                'runtime_shared_service' => true,
                'host' => '127.0.0.1',
                'port' => 20970,
                'token_scope_digest' => \hash('sha256', 'session_server.20970.token'),
                'probe' => $mode === 'shared_session' ? 'healthy' : 'unhealthy',
                'reason' => $reason,
            ];
        return [
            'mode' => $mode,
            'evidence' => $evidence,
            'evidence_digest' => \hash(
                'sha256',
                GatewayClient::canonicalJson($evidence),
            ),
        ];
    }

    private function stateFile(): string
    {
        if ($this->root === '') {
            $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
                . 'wls-gateway-capability-state-' . \bin2hex(\random_bytes(8));
            self::assertTrue(\mkdir($this->root, 0700, true));
        }
        return $this->root . DIRECTORY_SEPARATOR . 'capability.json';
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
            if ($item->isDir() && !$item->isLink()) {
                @\rmdir($path);
            } else {
                @\unlink($path);
            }
        }
        @\rmdir($root);
    }
}
