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

        if (isset($args['update-baseline'])) {
            $path = (string)($args['baseline'] ?? BP . '/app/code/Weline/Framework/Test/Unit/Architecture/architecture-baseline.json');
            $this->writeBaseline($report, $path);
            if ($json) {
                echo (string)json_encode(['written' => $path, 'counts' => $report->countsByRule()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
            } else {
                $this->printing->success(__('棘轮基线已写入 %{1}（%{2} 条违规）。', [$path, count($report->findings)]));
            }
            return;
        }

        if (isset($args['baseline'])) {
            $path = (string)$args['baseline'];
            if (!is_file($path)) {
                throw new ArchitectureViolationException(__('架构基线文件不存在：%{1}', [$path]));
            }
            $baseline = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $diff = $report->diffAgainstBaseline(is_array($baseline) ? $baseline : []);
            $regressions = $diff['regressions'];
            $ignoredPaths = array_values(array_filter(
                explode(',', (string)($args['ignore-file'] ?? '')),
                static fn(string $value): bool => trim($value) !== '',
            ));
            $ignoredCount = 0;
            if ($ignoredPaths !== []) {
                $kept = [];
                foreach ($regressions as $finding) {
                    $file = str_replace('\\', '/', (string)$finding['file']);
                    $matched = false;
                    foreach ($ignoredPaths as $ignored) {
                        $ignored = trim($ignored);
                        if ($file === $ignored || str_contains($file, '/' . ltrim($ignored, '/'))) {
                            $matched = true;
                            break;
                        }
                    }
                    if ($matched) {
                        ++$ignoredCount;
                        continue;
                    }
                    $kept[] = $finding;
                }
                $regressions = $kept;
            }

            if ($json) {
                echo (string)json_encode([
                    'clean' => $regressions === [],
                    'mode' => 'ratchet',
                    'metrics' => $report->metrics,
                    'counts' => $report->countsByRule(),
                    'resolved_counts' => $diff['resolved_counts'],
                    'ignored_count' => $ignoredCount,
                    'regressions' => $regressions,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
            } else {
                $this->printing->note(__('棘轮模式：对照基线 %{1}。', [$path]));
                if ($ignoredCount > 0) {
                    $this->printing->warning(__("已按 --ignore-file 排除 %{1} 条新增违规（不计入门禁）。", [$ignoredCount]));
                }
                foreach ($diff['resolved_counts'] as $rule => $count) {
                    $this->printing->success(__("%{1} 消退 %{2} 条，建议 --update-baseline 收紧基线。", [$rule, $count]));
                }
                foreach (array_slice($regressions, 0, 100) as $finding) {
                    $location = $finding['file'] === '' ? '' : " {$finding['file']}" . ($finding['line'] > 0 ? ":{$finding['line']}" : '');
                    $this->printing->error("[新增][{$finding['rule']}]{$location} {$finding['message']}");
                }
                if (count($regressions) > 100) {
                    $this->printing->warning(__('其余 %{1} 条新增违规已省略，使用 --json 查看全部。', [count($regressions) - 100]));
                }
            }

            if ($regressions !== []) {
                throw new ArchitectureViolationException(__(
                    '架构门禁失败：发现 %{1} 条基线之外的新增违规。',
                    [count($regressions)],
                ));
            }

            $this->printing->success(__('架构棘轮门禁通过：无新增违规。'));
            return;
        }

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

    private function writeBaseline(\Weline\Framework\Architecture\Report $report, string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new ArchitectureViolationException(__('无法创建基线目录：%{1}', [$dir]));
        }
        $payload = (string)json_encode($report->toBaseline(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $tmp = $path . '.tmp-' . getmypid();
        if (file_put_contents($tmp, $payload . PHP_EOL) === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new ArchitectureViolationException(__('无法写入基线文件：%{1}', [$path]));
        }
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
                '--baseline <path>' => __('棘轮模式：仅阻断基线之外的新增违规'),
                '--ignore-file <a,b>' => __('棘轮模式：按路径子串排除特定文件的新增违规（用于并发会话未收口的改动，不计入门禁）'),
                '--update-baseline' => __('把当前全部违规写入基线（配合 --baseline 指定路径）'),
                '-h, --help' => __('显示帮助信息'),
            ],
            [],
            [
                __('生产门禁') => 'php bin/w architecture:check',
                __('棘轮门禁') => 'php bin/w architecture:check --baseline app/code/Weline/Framework/Test/Unit/Architecture/architecture-baseline.json',
                __('生成/收紧基线') => 'php bin/w architecture:check --update-baseline',
                __('迁移基线') => 'php bin/w architecture:check --allow-legacy --json',
            ],
        );
    }
}
