<?php

declare(strict_types=1);

namespace Weline\Framework\Console\Console\Architecture;

use Weline\Framework\Architecture\ArchitectureAnalyzer;
use Weline\Framework\Architecture\Exception\ArchitectureViolationException;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Output\Cli\Printing;

final class Check extends CommandAbstract
{
    public function __construct(
        private readonly Printing $printing,
        private readonly ArchitectureAnalyzer $analyzer,
    ) {
    }

    public function execute(array $args = [], array $data = []): void
    {
        $allowLegacy = isset($args['allow-legacy']);
        $json = isset($args['json']);
        $root = BP . '/app/code/Weline';
        $report = $this->analyzer->analyze($root, $allowLegacy);

        if ($json) {
            echo (string)json_encode(
                $report->toArray(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ), PHP_EOL;
            return;
        } else {
            $metrics = $report->metrics;
            $this->printing->note(__(
                '架构检查：%{1} 个模块，%{2} 个 PHP 文件，%{3} 条跨模块引用。',
                [$metrics['modules'] ?? 0, $metrics['php_files'] ?? 0, $metrics['references'] ?? 0],
            ));
            foreach ($report->countsByRule() as $rule => $count) {
                $this->printing->warning("{$rule}: {$count}");
            }
            foreach (array_slice($report->findings, 0, 100) as $finding) {
                $location = $finding->file === '' ? '' : " {$finding->file}" . ($finding->line > 0 ? ":{$finding->line}" : '');
                $this->printing->error("[{$finding->rule}]{$location} {$finding->message}");
            }
            if (count($report->findings) > 100) {
                $remaining = count($report->findings) - 100;
                $this->printing->warning(__("其余 %{1} 条问题已省略，使用 --json 查看全部。", [$remaining]));
            }
        }

        if (!$report->isClean()) {
            throw new ArchitectureViolationException(__(
                '架构门禁失败：发现 %{1} 条违规。',
                [count($report->findings)],
            ));
        }

        $this->printing->success(__('架构门禁通过。'));
    }

    public function tip(): string
    {
        return __('检查模块零耦合、依赖声明、循环依赖和请求链路阻塞调用');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'architecture:check',
            $this->tip(),
            [
                '--json' => __('JSON 格式输出完整报告'),
                '--allow-legacy' => __('迁移期允许从 register.php 读取模块元数据，仍会报告 manifest.missing'),
                '-h, --help' => __('显示帮助信息'),
            ],
            [],
            [
                __('生产门禁') => 'php bin/w architecture:check',
                __('迁移基线') => 'php bin/w architecture:check --allow-legacy --json',
            ],
        );
    }
}
