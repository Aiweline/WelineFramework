<?php

declare(strict_types=1);

namespace Weline\Mail\Test\Unit\Env;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllMailEnvContractsPass().
 */
final class MailEnvContractsTest extends TestCase
{
    public function testAllMailEnvContractsPass(): void
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
            'MailSodiumRequirement::testMailRequirementsDeclareSodiumExtension' => function () {
            $this->assert_MailSodiumRequirement_testMailRequirementsDeclareSodiumExtension();
        },
            'MailSodiumRequirement::testManagementAdapterFailsClosedWithoutSodium' => function () {
            $this->assert_MailSodiumRequirement_testManagementAdapterFailsClosedWithoutSodium();
        },
            'StalwartInstallScript::testLinuxInstallScriptDelegatesToOfficialInstaller' => function () {
            $this->assert_StalwartInstallScript_testLinuxInstallScriptDelegatesToOfficialInstaller();
        },
            'StalwartInstallScript::testAdapterInstallNoLongerDefersOnlyToManualEnvInstall' => function () {
            $this->assert_StalwartInstallScript_testAdapterInstallNoLongerDefersOnlyToManualEnvInstall();
        },
        ];
    }

private function assert_MailSodiumRequirement_testMailRequirementsDeclareSodiumExtension(): void
{
    $req = require '/Users/weline/Project/Official/框架/app/code/Weline/Mail' . '/env/requirements.php';
    self::assertIsArray($req);
    self::assertContains('sodium', $req['extensions'] ?? []);
}

private function assert_MailSodiumRequirement_testManagementAdapterFailsClosedWithoutSodium(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Mail' . '/Service/StalwartManagementAdapter.php');
    self::assertStringContainsString('sodium_crypto_secretbox', $src);
    self::assertStringContainsString('PHP Sodium 扩展不可用', $src);
}

private function assert_StalwartInstallScript_testLinuxInstallScriptDelegatesToOfficialInstaller(): void
{
    $script = '/Users/weline/Project/Official/框架/app/code/Weline/Mail' . '/env/script/install_stalwart_linux.sh';
    self::assertFileExists($script);
    $body = (string) file_get_contents($script);
    self::assertStringNotContainsString('Automatic binary download is intentionally not performed yet', $body);
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

private function assert_StalwartInstallScript_testAdapterInstallNoLongerDefersOnlyToManualEnvInstall(): void
{
    $adapter = '/Users/weline/Project/Official/框架/app/code/Weline/Mail' . '/Service/StalwartEngineAdapter.php';
    $body = (string) file_get_contents($adapter);
    self::assertStringNotContainsString('真实安装脚本已准备，但为避免误改系统服务', $body);
    self::assertStringContainsString("bash ' . escapeshellarg(\$script) . ' install'", $body);
    self::assertStringContainsString('resolveBinaryPath', $body);
}
}
