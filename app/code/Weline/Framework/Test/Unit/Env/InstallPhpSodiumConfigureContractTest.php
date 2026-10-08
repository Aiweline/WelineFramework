<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Env;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Env\Service\ExtensionInstallStrategyMap;

/**
 * Bundled PHP rebuild must always compile sodium (Mail / Gateway signing).
 */
final class InstallPhpSodiumConfigureContractTest extends TestCase
{
    public function testRequiredExtensionsAlwaysIncludeSodium(): void
    {
        $bash = (string)file_get_contents(dirname(__DIR__, 7) . '/bin/install.bash');
        self::assertStringContainsString('exts="$exts pcntl opcache sodium"', $bash);
        self::assertStringContainsString('sodium)     echo "--with-sodium"', $bash);
        self::assertStringContainsString('libsodium-dev', $bash);
        self::assertStringContainsString('--with-sodium=$brew_prefix/opt/libsodium', $bash);
    }

    public function testFrameworkEnvRequirementsDeclareSodium(): void
    {
        $req = require dirname(__DIR__, 3) . '/Env/env/requirements.php';
        self::assertIsArray($req);
        self::assertContains('sodium', $req['extensions'] ?? []);
    }

    public function testConfigurePhpIniRequiresSodium(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 7) . '/setup/server_installer/ConfigurePhpIni.php',
        );
        self::assertMatchesRegularExpression(
            "/getFrameworkRequiredExtensions\\(\\)[\\s\\S]*?'sodium'/",
            $src,
        );
    }

    public function testPeclPackageNameMapsSodiumToLibsodium(): void
    {
        $map = new ExtensionInstallStrategyMap();
        self::assertSame('libsodium', $map->getPeclPackageName('sodium'));
        self::assertSame('event', $map->getPeclPackageName('event'));
    }

    public function testInstallBashDoesNotTreatExtensionsKeyAsExtension(): void
    {
        $bash = (string)file_get_contents(dirname(__DIR__, 7) . '/bin/install.bash');
        self::assertStringContainsString("grep -Ev '^(extensions|recommended_extensions)$'", $bash);
        // Dry-run collector: key name must not appear in required ext set.
        $tmp = sys_get_temp_dir() . '/weline-sodium-ext-list-' . getmypid() . '.sh';
        $snippet = <<<'SH'
ROOT=__ROOT__
eval "$(sed -n '/^get_required_php_extensions()/,/^}/p' "$ROOT/bin/install.bash")"
get_required_php_extensions | tr '\n' ' '
SH;
        $snippet = str_replace('__ROOT__', dirname(__DIR__, 7), $snippet);
        file_put_contents($tmp, $snippet);
        $out = (string)shell_exec('bash ' . escapeshellarg($tmp) . ' 2>/dev/null');
        @unlink($tmp);
        self::assertStringContainsString('sodium', $out);
        self::assertStringNotContainsString(' extensions ', ' ' . $out . ' ');
    }
}
