<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Css;

use PHPUnit\Framework\TestCase;

/**
 * 模块前端页面修改标准（默认主题消费合同）· 机器门禁（棘轮）。
 *
 * 权威：app/code/Weline/Theme/doc/module-frontend-scope.md
 * 扫描器：app/code/Weline/Theme/bin/module-frontend-theme-audit.py
 * 基线：app/code/Weline/Theme/test/fixtures/module-frontend-theme-baseline.json
 *
 * 语义：
 *  - 基线里的每个 code / module / severity 计数是**上限**，只许降不许升；
 *  - 迁移一个模块后，用 `--write-baseline` 下调基线，回归会立刻变红；
 *  - 基线降到 0 后，`ok=true`，本门禁即等价于「零硬编码」。
 *
 * 例外（白名单在扫描器内实现，不是漏扫）：
 *  纸面钉 #0f1111/#565959/#666666 绑 chrome-body-text/amz-drawer-text/paper-text；
 *  @media 断点字面量；[hidden]{display:none!important}；prefers-reduced-motion 内 !important；
 *  部件 bake 通道（data-weline-widget-asset / data-weline-source-position）。
 */
final class ModuleFrontendThemeConsumptionContractTest extends TestCase
{
    private static function themeRoot(): string
    {
        return \dirname(__DIR__, 3);
    }

    private static function scannerPath(): string
    {
        return self::themeRoot() . '/bin/module-frontend-theme-audit.py';
    }

    private static function baselinePath(): string
    {
        return self::themeRoot() . '/test/fixtures/module-frontend-theme-baseline.json';
    }

    private static function scopeConfigPath(): string
    {
        return self::themeRoot() . '/bin/module-frontend-theme-scope.json';
    }

    /** @return array{report: array<string, mixed>, exit: int} */
    private function runScanner(): array
    {
        $python = \trim((string) \shell_exec('command -v python3 2>/dev/null'));
        if ($python === '') {
            self::markTestSkipped('python3 is required for the module frontend theme audit');
        }
        self::assertFileExists(self::scannerPath(), 'module frontend theme audit script is missing');
        self::assertFileExists(self::baselinePath(), 'module frontend theme baseline fixture is missing');

        $cmd = \escapeshellarg($python)
            . ' ' . \escapeshellarg(self::scannerPath())
            . ' --json --baseline ' . \escapeshellarg(self::baselinePath());
        $output = [];
        $exit = 0;
        \exec($cmd . ' 2>/dev/null', $output, $exit);
        $json = \implode("\n", $output);
        $report = \json_decode($json, true);
        self::assertIsArray($report, 'module frontend theme audit must emit JSON: ' . \substr($json, 0, 400));

        return ['report' => $report, 'exit' => $exit];
    }

    public function testModuleFrontendSurfacesDoNotExceedRatchetBaseline(): void
    {
        $run = $this->runScanner();
        $report = $run['report'];
        $ratchet = $report['ratchet'] ?? [];
        $problems = $ratchet['problems'] ?? [];

        self::assertSame(
            0,
            $run['exit'],
            "模块前端主题消费合同棘轮失败（只许降不许升）：\n  " . \implode("\n  ", (array) $problems)
            . "\n当前合计：" . (string) ($report['total_violations'] ?? '?')
            . '，基线：' . (string) ((\json_decode((string) \file_get_contents(self::baselinePath()), true)['total_violations'] ?? '?'))
            . "\n迁移后请执行：python3 app/code/Weline/Theme/bin/module-frontend-theme-audit.py --write-baseline "
            . self::baselinePath()
        );
        self::assertTrue((bool) ($ratchet['ok'] ?? false), 'scanner must report ratchet status');
    }

    public function testScannerKeepsFrontendConsumptionScope(): void
    {
        $config = \json_decode((string) \file_get_contents(self::scopeConfigPath()), true);
        self::assertIsArray($config, 'scope config must be valid JSON');
        self::assertSame('module-frontend-theme-scope.v1', (string) ($config['schema'] ?? ''));
        self::assertNotEmpty($config['exclude_modules'] ?? [], 'backend-area exclusions must stay explicit');
        self::assertContains(
            'Weline/Theme',
            $config['theme_providers'] ?? [],
            'default theme provider must never be audited as a consumer module'
        );

        $run = $this->runScanner();
        $scope = $run['report']['scope'] ?? [];
        self::assertSame('frontend_consumption', (string) ($scope['mode'] ?? ''),
            'audit must run in frontend consumption scope, not --all');
    }
}
