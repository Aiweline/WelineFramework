<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Session;

use PHPUnit\Framework\TestCase;
use Weline\Server\Session\Server\SessionServer;

final class SessionServerTokenCleanupTest extends TestCase
{
    public function testDifferentEndpointsCannotPublishToSameCapabilityPath(): void
    {
        $tokenFileName = 'session_server.token-cleanup-' . \str_replace('.', '-', (string) \microtime(true)) . '.token';
        $tokenPath = \Weline\Server\Service\SharedStateRuntimeScope::tokenFilePath($tokenFileName);
        $persistPath = \sys_get_temp_dir() . '/wls_session_token_cleanup_' . \getmypid() . '/';
        if (!\is_dir($persistPath)) {
            @\mkdir($persistPath, 0755, true);
        }

        $first = new SessionServer([
            'port' => 39170,
            'persist_path' => $persistPath,
            'token_file_name' => $tokenFileName,
        ]);
        $second = new SessionServer([
            'port' => 39171,
            'persist_path' => $persistPath,
            'token_file_name' => $tokenFileName,
        ]);

        try {
            self::assertTrue($first->start('127.0.0.1', 39170));
            self::assertFalse($second->start('127.0.0.1', 39171));
            self::assertFileExists($tokenPath);
            self::assertNotSame($first->getAuthToken(), $second->getAuthToken());

            $first->stop();
            self::assertFileExists($tokenPath);

            $second->stop();
            self::assertFileExists($tokenPath);
        } finally {
            $this->cleanupTokenArtifacts($tokenPath);
            if (\is_dir($persistPath)) {
                @\rmdir($persistPath);
            }
        }
    }

    public function testSameEndpointRestartAdvancesGenerationPastPriorFence(): void
    {
        $tokenFileName = 'session_server.token-restart-' . \bin2hex(\random_bytes(6)) . '.token';
        $tokenPath = \Weline\Server\Service\SharedStateRuntimeScope::tokenFilePath($tokenFileName);
        $persistPath = \sys_get_temp_dir() . '/wls_session_token_restart_' . \getmypid() . '/';
        if (!\is_dir($persistPath)) {
            @\mkdir($persistPath, 0755, true);
        }
        $first = new SessionServer([
            'port' => 39173,
            'persist_path' => $persistPath,
            'token_file_name' => $tokenFileName,
        ]);
        $version = new \ReflectionProperty($first, 'authTokenVersion');
        $version->setValue($first, 2_000_000_000);

        try {
            self::assertTrue($first->start('127.0.0.1', 39173));
            $firstState = \Weline\Server\Session\Server\SharedStateTokenStore::readPath($tokenPath);
            self::assertIsArray($firstState);
            $first->stop();

            $second = new SessionServer([
                'port' => 39173,
                'persist_path' => $persistPath,
                'token_file_name' => $tokenFileName,
            ]);
            try {
                self::assertTrue($second->start('127.0.0.1', 39173));
                $secondState = \Weline\Server\Session\Server\SharedStateTokenStore::readPath($tokenPath);
                self::assertIsArray($secondState);
                self::assertGreaterThan($firstState['version'], $secondState['version']);
            } finally {
                $second->stop();
            }
        } finally {
            $first->stop();
            $this->cleanupTokenArtifacts($tokenPath);
            if (\is_dir($persistPath)) {
                @\rmdir($persistPath);
            }
        }
    }

    public function testProcessInstanceNameDoesNotDriftDeterministicCapabilityAuthority(): void
    {
        $tokenFileName = 'session_server.token-authority-' . \bin2hex(\random_bytes(6)) . '.token';
        $tokenPath = \Weline\Server\Service\SharedStateRuntimeScope::tokenFilePath($tokenFileName);
        $persistPath = \sys_get_temp_dir() . '/wls_session_token_authority_' . \getmypid() . '/';
        if (!\is_dir($persistPath)) {
            @\mkdir($persistPath, 0755, true);
        }
        $server = new SessionServer([
            'port' => 39174,
            'persist_path' => $persistPath,
            'token_file_name' => $tokenFileName,
            'service_instance_name' => 'shared-session-process-label',
            'instance_name' => 'another-process-label',
        ]);

        try {
            self::assertTrue($server->start('127.0.0.1', 39174));
            $expectedAuthority = [
                'role' => 'session_server',
                'host' => '127.0.0.1',
                'port' => 39174,
                'instance' => \Weline\Server\Session\Server\SharedStateTokenStore::defaultInstance(
                    'session_server',
                    '127.0.0.1',
                    39174,
                ),
            ];
            $published = \Weline\Server\Session\Server\SharedStateTokenStore::readPath(
                $tokenPath,
                $expectedAuthority,
            );
            self::assertIsArray($published);
            self::assertSame($server->getAuthToken(), $published['secret']);
        } finally {
            $server->stop();
            $this->cleanupTokenArtifacts($tokenPath);
            if (\is_dir($persistPath)) {
                @\rmdir($persistPath);
            }
        }
    }

    public function testTickRestoresMissingTokenFileForRunningServer(): void
    {
        $tokenFileName = 'session_server.token-restore-' . \str_replace('.', '-', (string) \microtime(true)) . '.token';
        $tokenPath = \Weline\Server\Service\SharedStateRuntimeScope::tokenFilePath($tokenFileName);
        $persistPath = \sys_get_temp_dir() . '/wls_session_token_restore_' . \getmypid() . '/';
        if (!\is_dir($persistPath)) {
            @\mkdir($persistPath, 0755, true);
        }

        $server = new SessionServer([
            'port' => 39172,
            'persist_path' => $persistPath,
            'token_file_name' => $tokenFileName,
        ]);

        try {
            self::assertTrue($server->start('127.0.0.1', 39172));
            self::assertFileExists($tokenPath);
            @\unlink($tokenPath);
            self::assertFileDoesNotExist($tokenPath);

            $server->tick(0);

            self::assertFileExists($tokenPath);
            $published = \Weline\Server\Session\Server\SharedStateTokenStore::readPath($tokenPath);
            self::assertIsArray($published);
            self::assertSame($server->getAuthToken(), $published['secret']);
        } finally {
            $server->stop();
            $this->cleanupTokenArtifacts($tokenPath);
            if (\is_dir($persistPath)) {
                @\rmdir($persistPath);
            }
        }
    }

    public function testStartFailsClosedForLegacyTokenFile(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX file mode behavior is verified on Linux-like systems.');
        }

        $tokenFileName = 'session_server.token-stale-' . \str_replace('.', '-', (string) \microtime(true)) . '.token';
        $tokenPath = \Weline\Server\Service\SharedStateRuntimeScope::tokenFilePath($tokenFileName);
        $persistPath = \sys_get_temp_dir() . '/wls_session_token_stale_' . \getmypid() . '/';
        if (!\is_dir($persistPath)) {
            @\mkdir($persistPath, 0755, true);
        }
        if (!\is_dir(\dirname($tokenPath))) {
            @\mkdir(\dirname($tokenPath), 0755, true);
        }

        $legacy = \str_repeat('a', 64) . ':41';
        \file_put_contents($tokenPath, $legacy);
        \chmod($tokenPath, 0600);

        $server = new SessionServer([
            'port' => 0,
            'persist_path' => $persistPath,
            'token_file_name' => $tokenFileName,
        ]);

        try {
            self::assertFalse($server->start('127.0.0.1', 0));
            self::assertSame($legacy, \file_get_contents($tokenPath));
            self::assertStringContainsString(
                'controlled migration',
                (string)$server->getLastBindError(),
            );
        } finally {
            $server->stop();
            @\chmod($tokenPath, 0600);
            $this->cleanupTokenArtifacts($tokenPath);
            if (\is_dir($persistPath)) {
                @\rmdir($persistPath);
            }
        }
    }

    private function cleanupTokenArtifacts(string $tokenPath): void
    {
        foreach ([
            $tokenPath,
            $tokenPath . '.publication.lock',
            $tokenPath . '.generation.json',
            $tokenPath . '.generation.json.lock',
        ] as $path) {
            if (\is_file($path) && !\is_link($path)) {
                @\unlink($path);
            }
        }
    }
}
