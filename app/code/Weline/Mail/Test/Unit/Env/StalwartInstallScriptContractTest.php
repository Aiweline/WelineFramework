<?php

declare(strict_types=1);

namespace Weline\Mail\Test\Unit\Env;

use PHPUnit\Framework\TestCase;

/**
 * 合同：Stalwart Linux 依赖脚本必须能真实安装，禁止只打印计划 exit 1。
 */
final class StalwartInstallScriptContractTest extends TestCase
{
    public function testLinuxInstallScriptDelegatesToOfficialInstaller(): void
    {
        $script = dirname(__DIR__, 3) . '/env/script/install_stalwart_linux.sh';
        self::assertFileExists($script);
        $body = (string)file_get_contents($script);

        self::assertStringNotContainsString(
            'Automatic binary download is intentionally not performed yet',
            $body
        );
        self::assertStringContainsString('raw.githubusercontent.com/stalwartlabs/stalwart', $body);
        self::assertStringContainsString('install.sh', $body);
        self::assertStringContainsString('STALWART_INSTALL_DIR', $body);
        self::assertStringContainsString('/opt/stalwart', $body);
        self::assertMatchesRegularExpression('/ACTION=.*check/', $body);
        self::assertMatchesRegularExpression('/ACTION.*install/', $body);

        // check 在无二进制时应 exit 1，且不得假装已装
        $cmd = 'bash ' . escapeshellarg($script) . ' check';
        $output = [];
        $code = 0;
        exec($cmd . ' 2>&1', $output, $code);
        $text = implode("\n", $output);
        if ($code === 0) {
            self::assertStringContainsString('INSTALLED', $text);
        } else {
            self::assertSame(1, $code);
            self::assertStringContainsString('MISSING', $text);
        }
    }

    public function testAdapterInstallNoLongerDefersOnlyToManualEnvInstall(): void
    {
        $adapter = dirname(__DIR__, 3) . '/Service/StalwartEngineAdapter.php';
        $body = (string)file_get_contents($adapter);
        self::assertStringNotContainsString(
            '真实安装脚本已准备，但为避免误改系统服务',
            $body
        );
        self::assertStringContainsString("bash ' . escapeshellarg(\$script) . ' install'", $body);
        self::assertStringContainsString('resolveBinaryPath', $body);
    }
}
