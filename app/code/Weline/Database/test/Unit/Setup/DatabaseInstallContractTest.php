<?php
declare(strict_types=1);

namespace Weline\Database\Test\Unit\Setup;

use PHPUnit\Framework\TestCase;
use Weline\Database\Setup\Install;
use Weline\Framework\Module\Model\Module;
use Weline\Framework\Setup\InstallInterface;
use Weline\Framework\Setup\Service\SetupScriptContractValidator;

final class DatabaseInstallContractTest extends TestCase
{
    public function testInstalledScriptPassesTheFrameworkPreflightContract(): void
    {
        self::assertInstanceOf(InstallInterface::class, new Install());
        $module = new Module([
            'name' => 'Weline_Database',
            'base_path' => BP . 'app/code/Weline/Database/',
            'namespace_path' => 'Weline\\Database',
        ]);
        (new SetupScriptContractValidator())->assertInstallContract($module);
        self::assertSame('Weline\\Database', $module->getNamespacePath());
    }
}
