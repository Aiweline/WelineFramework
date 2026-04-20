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
}
