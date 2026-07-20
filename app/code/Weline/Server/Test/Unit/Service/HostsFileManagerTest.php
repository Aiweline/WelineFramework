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

    public function testUnixAdminCommandUsesHostsCommandEntryPoint(): void
    {
        if (!\defined('BP')) {
            \define('BP', \getcwd() . DIRECTORY_SEPARATOR);
        }

        $method = new ReflectionMethod(HostsFileManager::class, 'getAdminCommandForOs');
        $method->setAccessible(true);

        $command = $method->invoke(null, 'shop-a.weline.test', '127.0.0.1', 'Linux');

        self::assertStringContainsString('sudo', $command);
        self::assertStringContainsString('server:hosts:add', $command);
        self::assertStringContainsString('shop-a.weline.test', $command);
        self::assertStringContainsString('127.0.0.1', $command);
    }

    public function testMacAdminCommandUsesAuthopenInsteadOfOsascript(): void
    {
        if (!\defined('BP')) {
            \define('BP', \getcwd() . DIRECTORY_SEPARATOR);
        }

        $method = new ReflectionMethod(HostsFileManager::class, 'getAdminCommandForOs');
        $method->setAccessible(true);

        $command = $method->invoke(null, 'shop-a.weline.test', '127.0.0.1', 'Darwin');

        self::assertStringContainsString('/usr/libexec/authopen', $command);
        self::assertStringContainsString('-w -a', $command);
        self::assertStringContainsString('shop-a.weline.test', $command);
        self::assertStringNotContainsString('osascript', $command);
    }
}
