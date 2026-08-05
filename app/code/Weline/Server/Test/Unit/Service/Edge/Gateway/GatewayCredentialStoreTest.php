<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayCredentialStore;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;

final class GatewayCredentialStoreTest extends TestCase
{
    private string $root = '';
    private string $home = '';
    private string $project = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-credential-'
            . \bin2hex(\random_bytes(8));
        $this->home = $this->root . DIRECTORY_SEPARATOR . 'gateway';
        $this->project = $this->root . DIRECTORY_SEPARATOR . 'project';
        self::assertTrue(\mkdir($this->home . DIRECTORY_SEPARATOR . 'state', 0700, true));
        self::assertTrue(\mkdir($this->home . DIRECTORY_SEPARATOR . 'trust', 0700, true));
        self::assertTrue(\mkdir($this->project, 0700, true));
        \putenv('WLS_GATEWAY_TEST_MODE=1');
        \putenv('WLS_GATEWAY_HOME=' . $this->home);
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

    public function testCredentialIsHostBoundAndStoredOutsideProjectConfiguration(): void
    {
        $hostId = \bin2hex(\random_bytes(16));
        $projectUuid = '123e4567-e89b-42d3-a456-426614174010';
        self::assertNotFalse(\file_put_contents(
            $this->home . DIRECTORY_SEPARATOR . 'trust' . DIRECTORY_SEPARATOR . 'host-id',
            $hostId,
        ));
        $store = new GatewayCredentialStore(new GatewayPaths(), $this->project);
        $file = $store->install([
            'host_id' => $hostId,
            'project_uuid' => $projectUuid,
            'credential_id' => \bin2hex(\random_bytes(16)),
            'secret' => \bin2hex(\random_bytes(32)),
        ], $projectUuid);
        self::assertStringContainsString(
            DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'wls'
                . DIRECTORY_SEPARATOR . 'gateway' . DIRECTORY_SEPARATOR,
            $file,
        );
        self::assertStringNotContainsString(
            DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'etc',
            $file,
        );
        self::assertSame($hostId, $store->load($projectUuid)['host_id']);
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0600, \fileperms($file) & 0777);
            self::assertSame(
                (\stat($this->project))['uid'],
                (\stat($file))['uid'],
            );
            self::assertSame(
                (\stat($this->project))['gid'],
                (\stat($file))['gid'],
            );
        }

        self::assertNotFalse(\file_put_contents(
            $this->home . DIRECTORY_SEPARATOR . 'trust' . DIRECTORY_SEPARATOR . 'host-id',
            \bin2hex(\random_bytes(16)),
        ));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not enrolled');
        $store->load($projectUuid);
    }

    public function testCredentialRejectsProjectMismatchAndLinkedTarget(): void
    {
        $hostId = \bin2hex(\random_bytes(16));
        self::assertNotFalse(\file_put_contents(
            $this->home . DIRECTORY_SEPARATOR . 'trust' . DIRECTORY_SEPARATOR . 'host-id',
            $hostId,
        ));
        $store = new GatewayCredentialStore(new GatewayPaths(), $this->project);
        try {
            $store->install([
                'host_id' => $hostId,
                'project_uuid' => '123e4567-e89b-42d3-a456-426614174011',
                'credential_id' => \bin2hex(\random_bytes(16)),
                'secret' => \bin2hex(\random_bytes(32)),
            ], '123e4567-e89b-42d3-a456-426614174012');
            self::fail('Cross-project credential was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('invalid', \strtolower($exception->getMessage()));
        }

        if (\PHP_OS_FAMILY === 'Windows') {
            return;
        }
        $directory = $this->project . DIRECTORY_SEPARATOR . 'var/wls/gateway';
        self::assertTrue(\mkdir($directory, 0700, true));
        self::assertTrue(\symlink('/tmp', $directory . DIRECTORY_SEPARATOR . $hostId . '.cred'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('symbolic link');
        $store->install([
            'host_id' => $hostId,
            'project_uuid' => '123e4567-e89b-42d3-a456-426614174011',
            'credential_id' => \bin2hex(\random_bytes(16)),
            'secret' => \bin2hex(\random_bytes(32)),
        ], '123e4567-e89b-42d3-a456-426614174011');
    }

    public function testPendingRotationCredentialDoesNotReplaceActiveUntilCommit(): void
    {
        $hostId = \bin2hex(\random_bytes(16));
        self::assertNotFalse(\file_put_contents(
            $this->home . DIRECTORY_SEPARATOR . 'trust' . DIRECTORY_SEPARATOR . 'host-id',
            $hostId,
        ));
        $oldUuid = '123e4567-e89b-42d3-a456-426614174021';
        $newUuid = '123e4567-e89b-42d3-a456-426614174022';
        $oldId = \bin2hex(\random_bytes(16));
        $newId = \bin2hex(\random_bytes(16));
        $rotationId = \bin2hex(\random_bytes(16));
        $store = new GatewayCredentialStore(new GatewayPaths(), $this->project);
        $store->install([
            'host_id' => $hostId,
            'project_uuid' => $oldUuid,
            'credential_id' => $oldId,
            'secret' => \bin2hex(\random_bytes(32)),
        ], $oldUuid);
        $store->installPending([
            'host_id' => $hostId,
            'project_uuid' => $newUuid,
            'credential_id' => $newId,
            'secret' => \bin2hex(\random_bytes(32)),
        ], $newUuid, $rotationId);

        self::assertSame($oldId, $store->load($oldUuid)['credential_id']);
        self::assertSame(
            $newId,
            $store->loadPending($rotationId, $newUuid)['credential_id'],
        );
        self::assertSame(
            $newId,
            $store->commitPending($rotationId, $newUuid)['credential_id'],
        );
        self::assertSame($newId, $store->load($newUuid)['credential_id']);
    }

    public function testCredentialStoreRejectsTheFilesystemRootAsProjectRoot(): void
    {
        $hostId = \bin2hex(\random_bytes(16));
        self::assertNotFalse(\file_put_contents(
            $this->home . DIRECTORY_SEPARATOR . 'trust' . DIRECTORY_SEPARATOR . 'host-id',
            $hostId,
        ));
        $projectRoot = DIRECTORY_SEPARATOR === '/'
            ? '/'
            : \substr((string)\realpath(\sys_get_temp_dir()), 0, 3);
        $projectUuid = '123e4567-e89b-42d3-a456-426614174031';
        $store = new GatewayCredentialStore(new GatewayPaths(), $projectRoot);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('safe project root');
        $store->install([
            'host_id' => $hostId,
            'project_uuid' => $projectUuid,
            'credential_id' => \bin2hex(\random_bytes(16)),
            'secret' => \bin2hex(\random_bytes(32)),
        ], $projectUuid);
    }

    public function testCredentialStoreRecognizesExtendedWindowsFilesystemRoots(): void
    {
        $store = new GatewayCredentialStore(new GatewayPaths(), $this->project);
        $method = new \ReflectionMethod($store, 'isFilesystemRoot');
        foreach ([
            '/',
            '///',
            'C:\\',
            '\\\\server\\',
            '\\\\server\\share\\',
            '\\\\?\\C:\\',
            '\\\\?\\UNC\\server\\share\\',
            '\\\\?\\UNC\\server\\',
            '\\\\.\\C:\\',
            '\\\\?\\Volume{01234567-89ab-cdef-0123-456789abcdef}\\',
        ] as $path) {
            self::assertTrue($method->invoke($store, $path), $path);
        }
        self::assertFalse($method->invoke(
            $store,
            '\\\\?\\UNC\\server\\share\\project',
        ));
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
