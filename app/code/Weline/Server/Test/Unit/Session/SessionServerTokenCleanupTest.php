<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Session;

use PHPUnit\Framework\TestCase;
use Weline\Server\Session\Server\SessionServer;

final class SessionServerTokenCleanupTest extends TestCase
{
    public function testStopDoesNotDeleteTokenFileWrittenByNewerProcess(): void
    {
        $tokenFileName = 'session_server.token-cleanup-' . \str_replace('.', '-', (string) \microtime(true)) . '.token';
        $tokenPath = BP . 'var/session/' . $tokenFileName;
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
            self::assertTrue($second->start('127.0.0.1', 39171));
            self::assertFileExists($tokenPath);
            self::assertNotSame($first->getAuthToken(), $second->getAuthToken());

            $first->stop();
            self::assertFileExists($tokenPath);

            $second->stop();
            self::assertFileDoesNotExist($tokenPath);
        } finally {
            if (\is_file($tokenPath)) {
                @\unlink($tokenPath);
            }
            if (\is_dir($persistPath)) {
                @\rmdir($persistPath);
            }
        }
    }

    public function testTickRestoresMissingTokenFileForRunningServer(): void
    {
        $tokenFileName = 'session_server.token-restore-' . \str_replace('.', '-', (string) \microtime(true)) . '.token';
        $tokenPath = BP . 'var/session/' . $tokenFileName;
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
            self::assertSame($server->getAuthToken(), \trim((string) @\file_get_contents($tokenPath)));
        } finally {
            $server->stop();
            if (\is_file($tokenPath)) {
                @\unlink($tokenPath);
            }
            if (\is_dir($persistPath)) {
                @\rmdir($persistPath);
            }
        }
    }
}
