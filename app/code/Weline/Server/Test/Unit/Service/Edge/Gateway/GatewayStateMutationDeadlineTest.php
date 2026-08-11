<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayCredentialStore;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;
use Weline\Server\Service\Edge\Gateway\ProjectIdentityStore;

final class GatewayStateMutationDeadlineTest extends TestCase
{
    private string $root = '';
    private string $gatewayHome = '';
    private string $projectRoot = '';
    private string $hostStateRoot = '';

    protected function setUp(): void
    {
        $temporaryRoot = \realpath(\sys_get_temp_dir());
        self::assertIsString($temporaryRoot);
        $this->root = $temporaryRoot . DIRECTORY_SEPARATOR
            . 'wls-state-deadline-' . \bin2hex(\random_bytes(8));
        $this->gatewayHome = $this->root . DIRECTORY_SEPARATOR . 'gateway';
        $this->projectRoot = $this->root . DIRECTORY_SEPARATOR . 'project';
        $this->hostStateRoot = $this->root . DIRECTORY_SEPARATOR . 'host-state';
        self::assertTrue(\mkdir(
            $this->gatewayHome . DIRECTORY_SEPARATOR . 'state',
            0700,
            true,
        ));
        self::assertTrue(\mkdir(
            $this->gatewayHome . DIRECTORY_SEPARATOR . 'trust',
            0700,
            true,
        ));
        self::assertTrue(\mkdir(
            $this->projectRoot . DIRECTORY_SEPARATOR . 'app/etc',
            0700,
            true,
        ));
        self::assertTrue(\mkdir($this->hostStateRoot, 0700, true));
        \putenv('WLS_GATEWAY_TEST_MODE=1');
        \putenv('WLS_GATEWAY_HOME=' . $this->gatewayHome);
        \putenv('WLS_GATEWAY_LISTEN_HTTP=28080');
        \putenv('WLS_GATEWAY_LISTEN_HTTPS=28443');
    }

    protected function tearDown(): void
    {
        \putenv('WLS_GATEWAY_TEST_MODE');
        \putenv('WLS_GATEWAY_HOME');
        \putenv('WLS_GATEWAY_LISTEN_HTTP');
        \putenv('WLS_GATEWAY_LISTEN_HTTPS');
        $this->removeTree($this->root);
    }

    public function testCredentialMutationCannotInheritLegacyLockWait(): void
    {
        $hostId = \str_repeat('a', 32);
        self::assertNotFalse(\file_put_contents(
            $this->gatewayHome . DIRECTORY_SEPARATOR . 'trust/host-id',
            $hostId,
        ));
        $projectUuid = '123e4567-e89b-42d3-a456-426614174101';
        $store = new GatewayCredentialStore(new GatewayPaths(), $this->projectRoot);
        $initial = $this->credential($hostId, $projectUuid, '1');
        $replacement = $this->credential($hostId, $projectUuid, '2');
        $store->install($initial, $projectUuid);
        $lock = @\fopen(
            $this->projectRoot . DIRECTORY_SEPARATOR
                . 'var/wls/gateway/.credentials.lock',
            'c+b',
        );
        self::assertIsResource($lock);
        self::assertTrue(@\flock($lock, LOCK_EX | LOCK_NB));
        $started = \hrtime(true) / 1_000_000_000;
        try {
            $store->install(
                $replacement,
                $projectUuid,
                (\hrtime(true) / 1_000_000_000) + 0.15,
            );
            self::fail('Credential mutation inherited an unbounded lock wait.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'lock',
                \strtolower($exception->getMessage()),
            );
            self::assertLessThan(
                1.0,
                (\hrtime(true) / 1_000_000_000) - $started,
            );
        } finally {
            @\flock($lock, LOCK_UN);
            @\fclose($lock);
        }
        self::assertSame(
            $initial['credential_id'],
            $store->load($projectUuid)['credential_id'],
        );
    }

    public function testNestedHostClaimSnapshotSharesIdentityDeadline(): void
    {
        $store = new ProjectIdentityStore(
            $this->projectRoot,
            $this->hostStateRoot,
            $this->root . DIRECTORY_SEPARATOR . 'missing-legacy.json',
        );
        $projectUuid = $store->projectUuid(
            (\hrtime(true) / 1_000_000_000) + 2.0,
        );
        $lock = @\fopen(
            $this->hostStateRoot . DIRECTORY_SEPARATOR
                . 'project-identities/' . $projectUuid . '.json.lock',
            'c+b',
        );
        self::assertIsResource($lock);
        self::assertTrue(@\flock($lock, LOCK_EX | LOCK_NB));
        $started = \hrtime(true) / 1_000_000_000;
        try {
            $store->clonedIdentityConflict(
                (\hrtime(true) / 1_000_000_000) + 0.15,
            );
            self::fail('Nested host-claim discovery inherited a 300-second wait.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'lock',
                \strtolower($exception->getMessage()),
            );
            self::assertLessThan(
                1.0,
                (\hrtime(true) / 1_000_000_000) - $started,
            );
        } finally {
            @\flock($lock, LOCK_UN);
            @\fclose($lock);
        }
    }

    public function testCredentialRecoveryIdentityProofSharesMutationDeadline(): void
    {
        $hostId = \str_repeat('b', 32);
        self::assertNotFalse(\file_put_contents(
            $this->gatewayHome . DIRECTORY_SEPARATOR . 'trust/host-id',
            $hostId,
        ));
        $identity = new ProjectIdentityStore($this->projectRoot);
        $projectUuid = $identity->projectUuid(
            (\hrtime(true) / 1_000_000_000) + 2.0,
        );
        $credential = $this->credential($hostId, $projectUuid, '3');
        $store = new GatewayCredentialStore(new GatewayPaths(), $this->projectRoot);
        $active = $store->install($credential, $projectUuid);
        $backup = $active . '.wls-backup-' . \str_repeat('c', 16);
        self::assertTrue(\copy($active, $backup));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($backup, 0600));
        }
        $lock = @\fopen(
            $this->projectRoot . DIRECTORY_SEPARATOR . 'app/etc/.wls-project.lock',
            'c+b',
        );
        self::assertIsResource($lock);
        self::assertTrue(@\flock($lock, LOCK_EX | LOCK_NB));
        $started = \hrtime(true) / 1_000_000_000;
        try {
            $store->install(
                $credential,
                $projectUuid,
                (\hrtime(true) / 1_000_000_000) + 0.15,
            );
            self::fail('Credential recovery proof inherited an identity lock wait.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'invalid',
                \strtolower($exception->getMessage()),
            );
            self::assertLessThan(
                1.0,
                (\hrtime(true) / 1_000_000_000) - $started,
            );
        } finally {
            @\flock($lock, LOCK_UN);
            @\fclose($lock);
        }
        self::assertFileExists($backup);
        self::assertSame(
            $credential['credential_id'],
            $store->load($projectUuid)['credential_id'],
        );
    }

    /** @return array<string,mixed> */
    private function credential(
        string $hostId,
        string $projectUuid,
        string $seed,
    ): array {
        return [
            'host_id' => $hostId,
            'project_uuid' => $projectUuid,
            'credential_id' => \str_repeat($seed, 32),
            'secret' => \str_repeat($seed, 64),
        ];
    }

    private function removeTree(string $path): void
    {
        if ($path === '' || (!\file_exists($path) && !\is_link($path))) {
            return;
        }
        if (\is_link($path) || !\is_dir($path)) {
            @\unlink($path);
            return;
        }
        $entries = @\scandir($path);
        if (\is_array($entries)) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $this->removeTree($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        @\rmdir($path);
    }
}
