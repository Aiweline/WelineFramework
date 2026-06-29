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

    public function testMacAdminCommandUsesSameHostsCommandEntryPoint(): void
    {
        if (!\defined('BP')) {
            \define('BP', \getcwd() . DIRECTORY_SEPARATOR);
        }

        $method = new ReflectionMethod(HostsFileManager::class, 'getAdminCommandForOs');
        $method->setAccessible(true);

        $command = $method->invoke(null, 'shop-a.weline.test', '127.0.0.1', 'Darwin');

        self::assertStringContainsString('osascript', $command);
        self::assertStringContainsString('with administrator privileges', $command);
        self::assertStringContainsString('server:hosts:add', $command);
    }
}
