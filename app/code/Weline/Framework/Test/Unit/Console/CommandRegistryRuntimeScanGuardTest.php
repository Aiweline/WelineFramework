<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Weline\Framework\Console\Console\Command\Upgrade;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\File\Scan;
use Weline\Server\Console\Server\Reload;

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

    public function testIncrementalScanKeepsAlreadyDeclaredServerCommand(): void
    {
        class_exists(Reload::class);

        $upgrade = (new ReflectionClass(Upgrade::class))->newInstanceWithoutConstructor();
        $scanProperty = new \ReflectionProperty(Upgrade::class, 'scan');
        $scanProperty->setAccessible(true);
        $scanProperty->setValue($upgrade, ObjectManager::getInstance(Scan::class));

        $method = new ReflectionMethod(Upgrade::class, 'scanModulesCommands');
        $method->setAccessible(true);
        $commands = $method->invoke($upgrade, ['Weline_Server']);

        self::assertTrue($this->hasCommand($commands, 'server:reload'), 'Incremental command scan must retain server:reload when the class is already loaded.');
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

    private function hasCommand(array $groups, string $name): bool
    {
        foreach ($groups as $commands) {
            if (is_array($commands) && isset($commands[$name])) {
                return true;
            }
        }

        return false;
    }
}
