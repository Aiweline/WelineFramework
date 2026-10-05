<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Console\E2e;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\Service\E2eBrowserCleanupService;

/**
 * php bin/w e2e:cleanup-browsers
 *
 * Reap orphaned Playwright / chrome-devtools-mcp leftovers after e2e or Agent WB-OP.
 * Does not quit the user's daily Google Chrome.
 */
class CleanupBrowsers extends CommandAbstract
{
    /** @var list<string> */
    public const ALIASES = [
        'e2e:cleanup-browsers',
    ];

    public function execute(array $args = [], array $data = []): int
    {
        $dryRun = $this->hasFlag($args, 'dry-run');
        $closeTabs = $this->hasFlag($args, 'close-acceptance-tabs');
        /** @var E2eBrowserCleanupService $service */
        $service = ObjectManager::getInstance(E2eBrowserCleanupService::class);
        $report = $service->cleanup([
            'dry_run' => $dryRun,
            'close_acceptance_tabs' => $closeTabs,
        ]);

        if (!($report['ok'] ?? false) && !empty($report['error'])) {
            $this->printer->error((string)$report['error']);
            if (!empty($report['raw'])) {
                $this->printer->warning((string)$report['raw']);
            }

            return 1;
        }

        $this->printer->success(__(
            '浏览器残留清理完成：killed=%{1} removed=%{2} closed_tabs=%{3} dry_run=%{4}',
            [
                (string)count($report['killedPids'] ?? []),
                (string)count($report['removedPaths'] ?? []),
                (string)($report['closedTabs'] ?? 0),
                !empty($report['dryRun']) ? '1' : '0',
            ]
        ));
        if (!empty($report['killedPids'])) {
            $this->printer->note('pids: ' . implode(', ', $report['killedPids']));
        }
        if (!empty($report['removedPaths'])) {
            $this->printer->note('paths: ' . implode(', ', $report['removedPaths']));
        }
        foreach ($report['skipped'] ?? [] as $skip) {
            $this->printer->warning((string)$skip);
        }

        return 0;
    }

    public function tip(): string
    {
        return __('清理 Playwright/MCP 遗留的自动化浏览器进程与临时配置（不退出日常 Chrome）');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'e2e:cleanup-browsers [--dry-run] [--close-acceptance-tabs]',
            __('在 e2e:run / Agent 验收后清理 chrome-headless-shell、Chrome for Testing、失效 chrome-devtools-mcp 与 /tmp 临时 profile。不退出用户日常 Google Chrome。'),
            [
                '--dry-run' => __('只报告将清理的目标，不实际 kill/删除'),
                '--close-acceptance-tabs' => __('额外关闭系统 Chrome 中 *.test.weline.com / *.weline.test 验收标签（macOS）'),
            ],
            [
                __('E2E 后清理') => 'php bin/w e2e:cleanup-browsers',
                __('干跑') => 'php bin/w e2e:cleanup-browsers --dry-run',
                __('顺带关验收标签') => 'php bin/w e2e:cleanup-browsers --close-acceptance-tabs',
            ]
        );
    }

    /**
     * @param array<string|int,mixed> $args
     */
    private function hasFlag(array $args, string $flag): bool
    {
        $needle = '--' . $flag;
        foreach ($args as $key => $value) {
            if (is_string($key) && ($key === $flag || $key === $needle)) {
                return true;
            }
            if (is_string($value) && ($value === $flag || $value === $needle || str_starts_with($value, $needle . '='))) {
                return true;
            }
        }

        return false;
    }
}
