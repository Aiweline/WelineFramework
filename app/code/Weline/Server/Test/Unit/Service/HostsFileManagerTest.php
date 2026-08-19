<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Server\Service\HostsFileManager;

final class HostsFileManagerTest extends TestCase
{
    public function testAddDomainToContentCreatesManagedBlockWhenMissing(): void
    {
        $method = new ReflectionMethod(HostsFileManager::class, 'addDomainToContent');
        $method->setAccessible(true);

        $result = $method->invoke(null, "127.0.0.1 localhost\n", 'shop-a.weline.test', '127.0.0.1');

        self::assertStringContainsString('# Weline WLS Auto-Config Start', $result);
        self::assertStringContainsString('127.0.0.1 shop-a.weline.test', $result);
        self::assertStringContainsString('# Weline WLS Auto-Config End', $result);
    }

    public function testAddDomainToContentAppendsInsideExistingManagedBlock(): void
    {
        $method = new ReflectionMethod(HostsFileManager::class, 'addDomainToContent');
        $method->setAccessible(true);

        $content = <<<HOSTS
127.0.0.1 localhost
# Weline WLS Auto-Config Start
127.0.0.1 shop-a.weline.test
# Weline WLS Auto-Config End
HOSTS;

        $result = $method->invoke(null, $content, 'shop-b.weline.test', '127.0.0.1');

        self::assertStringContainsString('127.0.0.1 shop-a.weline.test', $result);
        self::assertStringContainsString('127.0.0.1 shop-b.weline.test', $result);
        self::assertSame(1, substr_count($result, '# Weline WLS Auto-Config Start'));
    }

    public function testRewriteDomainIpRepairsWrongManagedLocalEntry(): void
    {
        $content = <<<HOSTS
127.0.0.1 localhost
# Weline WLS Auto-Config Start
192.168.88.10 shop-a.weline.test
# Weline WLS Auto-Config End
HOSTS;

        $rewrite = new ReflectionMethod(HostsFileManager::class, 'rewriteDomainIpInContent');
        $rewrite->setAccessible(true);
        $result = $rewrite->invoke(null, $content, 'shop-a.weline.test', '127.0.0.1');

        self::assertStringContainsString('127.0.0.1 shop-a.weline.test', $result);
        self::assertStringNotContainsString('192.168.88.10 shop-a.weline.test', $result);
        self::assertSame('127.0.0.1', HostsFileManager::resolveIpForDomain('shop-a.weline.test', '10.0.0.8'));
        self::assertSame('127.0.0.1', HostsFileManager::resolveIpForDomain('demo.local.test', '203.0.113.9'));
    }

    public function testCorrectReadOnlyEntryIsSatisfiedBeforePermissionChecks(): void
    {
        $path = \tempnam(\sys_get_temp_dir(), 'wls-hosts-read-');
        self::assertIsString($path);
        try {
            self::assertNotFalse(\file_put_contents(
                $path,
                "127.0.0.1 localhost\n127.0.0.1 shop-a.weline.test\n",
            ));
            self::assertTrue(\chmod($path, 0444));

            $method = new ReflectionMethod(HostsFileManager::class, 'inspectSatisfiedAddStatus');
            $method->setAccessible(true);
            self::assertSame(
                'external_satisfied',
                $method->invoke(null, $path, 'shop-a.weline.test', '127.0.0.1'),
            );

            $add = new ReflectionMethod(HostsFileManager::class, 'addDomain');
            $lines = \file($add->getFileName());
            self::assertIsArray($lines);
            $source = \implode('', \array_slice(
                $lines,
                $add->getStartLine() - 1,
                $add->getEndLine() - $add->getStartLine() + 1,
            ));
            $inspectionAt = \strpos($source, 'inspectSatisfiedAddStatus(');
            $permissionAt = \strpos($source, '!\\is_writable($hostsFile)');
            self::assertIsInt($inspectionAt);
            self::assertIsInt($permissionAt);
            self::assertLessThan($permissionAt, $inspectionAt);
        } finally {
            @\chmod($path, 0600);
            @\unlink($path);
        }
    }

    public function testRootWriterIsRestrictedToARootOwnedPosixTarget(): void
    {
        $method = new ReflectionMethod(HostsFileManager::class, 'directWriterIdentityAllowed');
        $method->setAccessible(true);

        self::assertTrue($method->invoke(null, ['uid' => 0], 'Darwin', 0));
        self::assertFalse($method->invoke(null, ['uid' => 501], 'Darwin', 0));
        self::assertFalse($method->invoke(null, ['uid' => 0], 'Windows', 0));
    }

    public function testPermissionDeniedResultRequestsBoundedAdministratorAuthorization(): void
    {
        self::assertTrue(\method_exists(HostsFileManager::class, 'permissionDeniedResult'));
        $method = new ReflectionMethod(HostsFileManager::class, 'permissionDeniedResult');
        $method->setAccessible(true);

        $result = $method->invoke(null, 'shop-a.weline.test', '127.0.0.1');
        self::assertIsArray($result);
        self::assertFalse($result['success'] ?? true);
        self::assertTrue($result['needs_admin'] ?? false);
        self::assertArrayNotHasKey('command', $result);
        self::assertStringContainsString('127.0.0.1 shop-a.weline.test', (string)$result['message']);
        self::assertStringContainsString('administrator authorization', \strtolower((string)$result['message']));
        self::assertStringNotContainsString('manually', \strtolower((string)$result['message']));
        self::assertStringNotContainsString('php', \strtolower((string)$result['message']));
        self::assertStringNotContainsString('bin/w', \strtolower((string)$result['message']));
    }
}
