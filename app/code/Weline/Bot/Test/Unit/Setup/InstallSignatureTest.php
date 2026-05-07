<?php

declare(strict_types=1);

namespace Weline\Bot\Test\Unit\Setup;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Bot\Setup\Install;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Db\Setup as DbSetup;
use Weline\Framework\Setup\InstallInterface;

class InstallSignatureTest extends TestCase
{
    public function testInstallSetupUsesFrameworkSetupDataContextContract(): void
    {
        $this->assertTrue(is_subclass_of(Install::class, InstallInterface::class));

        $method = new ReflectionMethod(Install::class, 'setup');
        $parameters = $method->getParameters();

        $this->assertCount(2, $parameters);
        $this->assertSame(Setup::class, $parameters[0]->getType()?->getName());
        $this->assertSame(Context::class, $parameters[1]->getType()?->getName());
        $this->assertSame('void', (string) $method->getReturnType());
    }

    public function testInstallUsesAvailableSetupDataApi(): void
    {
        $installSource = file_get_contents(
            dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'Setup' . DIRECTORY_SEPARATOR . 'Install.php'
        );

        $this->assertTrue(method_exists(Setup::class, 'getDbSetup'));
        $this->assertTrue(method_exists(DbSetup::class, 'getTable'));
        $this->assertTrue(method_exists(DbSetup::class, 'getConnector'));
        $this->assertStringContainsString('->getDbSetup()', $installSource);
        $this->assertStringNotContainsString('$setup->getTable(', $installSource);
        $this->assertStringNotContainsString('$setup->getConnection(', $installSource);
    }
}
