<?php

declare(strict_types=1);

namespace Weline\Framework\UnitTest\Console\E2e;

use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;

class Run extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): int
    {
        if (Env::system('deploy') !== 'dev') {
            $this->printer->setup(__('非开发环境禁止运行！如你确认是dev环境，请运行 php bin/w deploy:model:set dev 后重试。'));
            return 1;
        }

        $e2eDir = BP . 'tests' . DS . 'e2e';
        if (!is_dir($e2eDir)) {
            $this->printer->error(__('未找到 E2E 目录：%{1}', [$e2eDir]));
            return 1;
        }

        $playwrightArgs = $this->buildPlaywrightArgs($args);
        if (!$this->hasAnyHeadMode($playwrightArgs)) {
            $playwrightArgs[] = '--headed';
        }

        $command = $this->buildShellCommand($e2eDir, $playwrightArgs);

        $this->printer->note(__('工作目录：%{1}', [$e2eDir]));
        $this->printer->note(__('执行命令：%{1}', [$command]));
        echo "\n";

        $exitCode = 1;
        passthru($command, $exitCode);

        echo "\n";
        if ($exitCode === 0) {
            $this->printer->success(__('E2E 测试执行成功。'));
        } else {
            $this->printer->error(__('E2E 测试执行失败，退出码：%{1}', [$exitCode]));
        }

        return (int)$exitCode;
    }

    public function tip(): string
    {
        return __('运行 Playwright E2E（自动在 tests/e2e 目录执行）');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'e2e:run [spec] [options]',
            __('直接通过框架命令驱动 Playwright E2E，无需手动 cd tests/e2e。'),
            [
                '[spec]' => __('可选，指定测试文件或目录，例如 specs/backend/WeShop_Cart-smoke-backend.spec.js'),
                '--project=NAME' => __('指定 Playwright project，如 chromium/firefox/webkit'),
                '--headed' => __('有界面模式（默认会自动追加）'),
                '--headless' => __('无界面模式'),
                '--ui' => __('Playwright UI 模式'),
                '--grep=PATTERN' => __('按测试名过滤'),
                '--workers=N' => __('并发 worker 数'),
                '--module=Vendor_Module' => __('透传给现有 E2E 逻辑的模块过滤参数'),
            ],
            [],
            [
                __('运行全部 E2E') => 'php bin/w e2e:run',
                __('运行单个后端用例') => 'php bin/w e2e:run specs/backend/WeShop_Cart-smoke-backend.spec.js --project=chromium',
                __('按模块运行') => 'php bin/w e2e:run --module=WeShop_Cart --project=chromium',
                __('UI 调试') => 'php bin/w e2e:run --ui --project=chromium',
            ]
        );
    }

    /**
     * 将框架命令参数透传为 Playwright CLI 参数。
     */
    private function buildPlaywrightArgs(array $args): array
    {
        $result = [];
        $aliasMap = [
            'p' => 'project',
            'g' => 'grep',
            'w' => 'workers',
            'm' => 'module',
        ];

        foreach ($args as $key => $value) {
            if (is_int($key)) {
                if (is_string($value) && $value !== '' && !str_starts_with($value, '-') && !str_contains($value, ':')) {
                    $result[] = $value;
                }
                continue;
            }

            if (!is_string($key) || $key === 'h' || $key === 'help' || $key === 'command') {
                continue;
            }

            $normalizedKey = ltrim($key, '-');
            if ($normalizedKey === '' || str_contains($normalizedKey, ':')) {
                continue;
            }
            if (isset($aliasMap[$normalizedKey])) {
                $normalizedKey = $aliasMap[$normalizedKey];
            }

            if ($value === true || $value === 'true' || $value === 1 || $value === '1') {
                $result[] = '--' . $normalizedKey;
                continue;
            }

            if ($value === false || $value === 'false' || $value === 0 || $value === '0' || $value === null) {
                continue;
            }

            $result[] = '--' . $normalizedKey . '=' . (string)$value;
        }

        return array_values(array_unique($result));
    }

    private function hasAnyHeadMode(array $args): bool
    {
        foreach ($args as $arg) {
            if ($arg === '--headed' || $arg === '--headless' || $arg === '--ui') {
                return true;
            }
        }
        return false;
    }

    private function buildShellCommand(string $e2eDir, array $playwrightArgs): string
    {
        $playwrightParts = array_merge(['node', 'node_modules/playwright/cli.js', 'test'], $playwrightArgs);
        $playwrightCommand = implode(' ', array_map(static fn(string $part): string => escapeshellarg($part), $playwrightParts));
        if (PHP_OS_FAMILY === 'Windows') {
            return 'cd /d ' . escapeshellarg($e2eDir) . ' && ' . $playwrightCommand;
        }
        return 'cd ' . escapeshellarg($e2eDir) . ' && ' . $playwrightCommand;
    }
}

