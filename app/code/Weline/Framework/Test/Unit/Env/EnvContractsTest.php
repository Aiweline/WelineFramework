<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Env;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Env\Console\Env\Install;
use Weline\Framework\Env\Service\EnvChecker;
use Weline\Framework\Env\Service\ExtensionInstallStrategyMap;

/**
 * Aggregated contract suite (merged from 4 *ContractTest files).
 * Observe one overall pass/fail via testAllEnvContractsPass().
 */
final class EnvContractsTest extends TestCase
{
    public function testAllEnvContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'EnvCheckerDatabaseDriverRequirement::testCheckerResolvesDriverByConfiguredDatabaseType' => function () {
            $this->assert_EnvCheckerDatabaseDriverRequirement_testCheckerResolvesDriverByConfiguredDatabaseType();
        },
            'InstallPhpPcntlConfigure::testRequiredExtensionsAlwaysIncludePcntl' => function () {
            $this->assert_InstallPhpPcntlConfigure_testRequiredExtensionsAlwaysIncludePcntl();
        },
            'InstallPhpSodiumConfigure::testRequiredExtensionsAlwaysIncludeSodium' => function () {
            $this->assert_InstallPhpSodiumConfigure_testRequiredExtensionsAlwaysIncludeSodium();
        },
            'InstallPhpSodiumConfigure::testFrameworkEnvRequirementsDeclareSodium' => function () {
            $this->assert_InstallPhpSodiumConfigure_testFrameworkEnvRequirementsDeclareSodium();
        },
            'InstallPhpSodiumConfigure::testConfigurePhpIniRequiresSodium' => function () {
            $this->assert_InstallPhpSodiumConfigure_testConfigurePhpIniRequiresSodium();
        },
            'InstallPhpSodiumConfigure::testPeclPackageNameMapsSodiumToLibsodium' => function () {
            $this->assert_InstallPhpSodiumConfigure_testPeclPackageNameMapsSodiumToLibsodium();
        },
            'InstallPhpSodiumConfigure::testInstallBashDoesNotTreatExtensionsKeyAsExtension' => function () {
            $this->assert_InstallPhpSodiumConfigure_testInstallBashDoesNotTreatExtensionsKeyAsExtension();
        },
            'InstallRebuildPhpSafety::testEnvInstallSkipsRebuildWhenAptBusy' => function () {
            $this->assert_InstallRebuildPhpSafety_testEnvInstallSkipsRebuildWhenAptBusy();
        },
            'InstallRebuildPhpSafety::testInstallBashRebuildBacksUpBeforeReplace' => function () {
            $this->assert_InstallRebuildPhpSafety_testInstallBashRebuildBacksUpBeforeReplace();
        },
        ];
    }

private function assert_EnvCheckerDatabaseDriverRequirement_testCheckerResolvesDriverByConfiguredDatabaseType(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Env/Service/EnvChecker.php');
    self::assertStringContainsString('resolveDatabaseDriverExtensions', $src);
    self::assertStringContainsString('resolveConfiguredDatabaseType', $src);
    self::assertStringContainsString('当前库类型不需要，已降为推荐', $src);
    self::assertStringContainsString("'pgsql'", $src);
    self::assertStringContainsString("'mysql'", $src);
    self::assertTrue(class_exists(EnvChecker::class));
}

private function assert_InstallPhpPcntlConfigure_testRequiredExtensionsAlwaysIncludePcntl(): void
{
    $bash = (string) file_get_contents('/Users/weline/Project/Official/框架' . '/bin/install.bash');
    self::assertStringContainsString('exts="$exts pcntl opcache sodium"', $bash);
    self::assertStringContainsString('pcntl)      echo "--enable-pcntl"', $bash);
    self::assertStringContainsString('WLS/多进程', $bash);
}

private function assert_InstallPhpSodiumConfigure_testRequiredExtensionsAlwaysIncludeSodium(): void
{
    $bash = (string) file_get_contents('/Users/weline/Project/Official/框架' . '/bin/install.bash');
    self::assertStringContainsString('exts="$exts pcntl opcache sodium"', $bash);
    self::assertStringContainsString('sodium)     echo "--with-sodium"', $bash);
    self::assertStringContainsString('libsodium-dev', $bash);
    self::assertStringContainsString('--with-sodium=$brew_prefix/opt/libsodium', $bash);
}

private function assert_InstallPhpSodiumConfigure_testFrameworkEnvRequirementsDeclareSodium(): void
{
    $req = require '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Env/env/requirements.php';
    self::assertIsArray($req);
    self::assertContains('sodium', $req['extensions'] ?? []);
}

private function assert_InstallPhpSodiumConfigure_testConfigurePhpIniRequiresSodium(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架' . '/setup/server_installer/ConfigurePhpIni.php');
    self::assertMatchesRegularExpression("/getFrameworkRequiredExtensions\\(\\)[\\s\\S]*?'sodium'/", $src);
}

private function assert_InstallPhpSodiumConfigure_testPeclPackageNameMapsSodiumToLibsodium(): void
{
    $map = new ExtensionInstallStrategyMap();
    self::assertSame('libsodium', $map->getPeclPackageName('sodium'));
    self::assertSame('event', $map->getPeclPackageName('event'));
}

private function assert_InstallPhpSodiumConfigure_testInstallBashDoesNotTreatExtensionsKeyAsExtension(): void
{
    $bash = (string) file_get_contents('/Users/weline/Project/Official/框架' . '/bin/install.bash');
    self::assertStringContainsString("grep -Ev '^(extensions|recommended_extensions)\$'", $bash);
    // Dry-run collector: key name must not appear in required ext set.
    $tmp = sys_get_temp_dir() . '/weline-sodium-ext-list-' . getmypid() . '.sh';
    $snippet = <<<'SH'
    ROOT=__ROOT__
    eval "$(sed -n '/^get_required_php_extensions()/,/^}/p' "$ROOT/bin/install.bash")"
    get_required_php_extensions | tr '\n' ' '
    SH;
    $snippet = str_replace('__ROOT__', '/Users/weline/Project/Official/框架', $snippet);
    file_put_contents($tmp, $snippet);
    $out = (string) shell_exec('bash ' . escapeshellarg($tmp) . ' 2>/dev/null');
    @unlink($tmp);
    self::assertStringContainsString('sodium', $out);
    self::assertStringNotContainsString(' extensions ', ' ' . $out . ' ');
}

private function assert_InstallRebuildPhpSafety_testEnvInstallSkipsRebuildWhenAptBusy(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Env/Console/Env/Install.php');
    self::assertStringContainsString('isLinuxPackageManagerBusy', $src);
    self::assertStringContainsString('跳过自动 --rebuild-php', $src);
    self::assertStringContainsString('/var/lib/dpkg/lock-frontend', $src);
    self::assertTrue(method_exists(Install::class, 'tip'));
}

private function assert_InstallRebuildPhpSafety_testInstallBashRebuildBacksUpBeforeReplace(): void
{
    $root = '/Users/weline/Project/Official/框架';
    $bashPath = $root . '/bin/install.bash';
    self::assertFileExists($bashPath);
    $bash = (string) file_get_contents($bashPath);
    self::assertStringContainsString('linux_package_manager_busy', $bash);
    self::assertStringContainsString('restore_php_rebuild_backup', $bash);
    self::assertStringContainsString('Moving existing PHP aside for rebuild', $bash);
    self::assertStringContainsString('Refusing --rebuild-php so the existing PHP', $bash);
    self::assertStringNotContainsString('Removing existing PHP at $dest (--rebuild-php) to recompile', $bash);
}
}
