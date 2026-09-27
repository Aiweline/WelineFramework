<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Env;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Env\Console\Env\Install;

/**
 * env:install auto --rebuild-php must not wipe a working project PHP when apt
 * is locked; install.bash must backup/restore on rebuild failure.
 */
final class InstallRebuildPhpSafetyContractTest extends TestCase
{
    public function testEnvInstallSkipsRebuildWhenAptBusy(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Env/Console/Env/Install.php',
        );
        self::assertStringContainsString('isLinuxPackageManagerBusy', $src);
        self::assertStringContainsString('跳过自动 --rebuild-php', $src);
        self::assertStringContainsString('/var/lib/dpkg/lock-frontend', $src);
        self::assertTrue(method_exists(Install::class, 'tip'));
    }

    public function testInstallBashRebuildBacksUpBeforeReplace(): void
    {
        $root = dirname(__DIR__, 7);
        $bashPath = $root . '/bin/install.bash';
        self::assertFileExists($bashPath);
        $bash = (string)file_get_contents($bashPath);
        self::assertStringContainsString('linux_package_manager_busy', $bash);
        self::assertStringContainsString('restore_php_rebuild_backup', $bash);
        self::assertStringContainsString('Moving existing PHP aside for rebuild', $bash);
        self::assertStringContainsString('Refusing --rebuild-php so the existing PHP', $bash);
        self::assertStringNotContainsString(
            'Removing existing PHP at $dest (--rebuild-php) to recompile',
            $bash,
        );
    }
}
