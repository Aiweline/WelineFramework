<?php

declare(strict_types=1);

namespace Weline\Bot\Test\Unit\Setup;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Weline\Bot\Setup\Install;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Db\Setup as DbSetup;
use Weline\Framework\Setup\InstallInterface;

final class InstallSignatureTest extends TestCase
{
    public function testInstallSetupSignatureMatchesFrameworkContract(): void
    {
        $installMethod = new ReflectionMethod(Install::class, 'setup');
        $interfaceMethod = new ReflectionMethod(InstallInterface::class, 'setup');

        self::assertSame($interfaceMethod->getNumberOfParameters(), $installMethod->getNumberOfParameters());

        $parameters = $installMethod->getParameters();

        self::assertSame(Setup::class, $parameters[0]->getType()?->getName());
        self::assertSame(Context::class, $parameters[1]->getType()?->getName());
    }

    public function testInstallSeedHelpersUseFrameworkDbSetupApi(): void
    {
        foreach (['createDefaultRole', 'createBuiltinSkills', 'registerBotAdapters'] as $method) {
            $seedMethod = new ReflectionMethod(Install::class, $method);
            self::assertSame(DbSetup::class, $seedMethod->getParameters()[0]->getType()?->getName());
        }
    }

    public function testInstallAvoidsUnsupportedSetupFacadeMethods(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(Install::class))->getFileName());

        self::assertStringContainsString('getDbSetup()', $source);
        self::assertStringNotContainsString('$setup->getTable(', $source);
        self::assertStringNotContainsString('$setup->getConnection(', $source);
        self::assertStringNotContainsString('SHOW TABLES LIKE', $source);
    }
}
