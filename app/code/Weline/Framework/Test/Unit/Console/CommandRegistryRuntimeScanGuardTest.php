<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Weline\Framework\Console\Console\Command\Upgrade;

final class CommandRegistryRuntimeScanGuardTest extends TestCase
{
    public function testCommandDiscoverySkipsPhpUnitBackedConsoleTestFixture(): void
    {
        self::assertTrue($this->shouldSkip($this->rootPath('app/code/Weline/Theme/Console/Resource/Compiler/WelineModulesTest.php')));
    }

    public function testCommandDiscoveryDoesNotSkipRealCommandsNamedTest(): void
    {
        self::assertFalse($this->shouldSkip($this->rootPath('app/code/Weline/Cron/Console/Cron/Test.php')));
        self::assertFalse($this->shouldSkip($this->rootPath('app/code/Weline/Seo/Console/Sitemap/Test.php')));
    }

    public function testCommandDiscoveryDoesNotSkipServerCommands(): void
    {
        self::assertFalse($this->shouldSkip($this->rootPath('app/code/Weline/Server/Console/Server/Status.php')));
        self::assertFalse($this->shouldSkip($this->rootPath('app/code/Weline/Server/Console/Server/Listing.php')));
        self::assertFalse($this->shouldSkip($this->rootPath('app/code/Weline/Server/Console/Server/Start.php')));
    }

    private function shouldSkip(string $path): bool
    {
        $upgrade = (new ReflectionClass(Upgrade::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(Upgrade::class, 'shouldSkipCommandScanFile');
        $method->setAccessible(true);

        return (bool)$method->invoke($upgrade, $path);
    }

    private function rootPath(string $path): string
    {
        return \dirname(__DIR__, 7) . '/' . $path;
    }
}
