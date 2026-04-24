<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * 反硬编码扫描命令（T31 MVP · 只读）
 *
 * 推荐用法（别名，计划 §12.1B 对齐）：
 *   php bin/w ai-site:anti-hardcode:scan
 *   php bin/w ai-site:anti-hardcode:scan --path=<abs> --format=json --severity=error
 *
 * 兼容命名（框架按类名自动派生）：
 *   php bin/w aisite:anti-hardcode-scan
 */

namespace GuoLaiRen\PageBuilder\Console\AiSite;

use GuoLaiRen\PageBuilder\Service\AiSiteAntiHardcodeScanService;
use Weline\Framework\Console\CommandInterface;

final class AntiHardcodeScan implements CommandInterface
{
    /**
     * 命令别名。
     *
     * 框架 {@see \Weline\Framework\Console\Console\Command\Upgrade} 在扫描命令类时
     * 会读取 `ALIASES` 常量，将这里声明的名字一并注入 commands.php。
     *
     * 本类文件名 `AntiHardcodeScan.php` 会被框架派生成 `aisite:anti-hardcode-scan`；
     * 但计划 §12.1B 对齐命名为 `ai-site:anti-hardcode:scan`，通过别名保持向后兼容：
     * 两个名字都可调用，行为完全一致。
     *
     * @var list<string>
     */
    public const ALIASES = [
        'ai-site:anti-hardcode:scan',
    ];

    /**
     * 默认扫描范围：AiSiteAgent 工作台模板目录。
     */
    private const DEFAULT_RELATIVE_PATH = 'app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/';

    public function execute(array $args = [], array $data = []): int
    {
        $path     = (string)($args['path'] ?? $data['path'] ?? '');
        $format   = \strtolower((string)($args['format'] ?? $data['format'] ?? 'text'));
        $severity = \strtolower((string)($args['severity'] ?? $data['severity'] ?? 'all'));

        if ($path === '') {
            $basePath = \defined('BP') ? (string)\BP : \getcwd() . \DIRECTORY_SEPARATOR;
            $path     = \rtrim($basePath, '/\\') . \DIRECTORY_SEPARATOR . self::DEFAULT_RELATIVE_PATH;
        }

        if (!\is_readable($path)) {
            echo "[anti-hardcode-scan] 路径不可读: $path\n";
            return 2;
        }

        $service = new AiSiteAntiHardcodeScanService();
        $summary = $service->scanPaths([$path]);

        $filtered = $this->filterBySeverity($summary['violations'], $severity);

        if ($format === 'json') {
            echo \json_encode([
                'path'       => $path,
                'severity'   => $severity,
                'totals'     => $summary['totals'],
                'violations' => $filtered,
            ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT) . "\n";
        } else {
            echo "[anti-hardcode-scan] 扫描路径: $path\n";
            echo "[anti-hardcode-scan] severity 过滤: $severity\n";
            echo "[anti-hardcode-scan] 命中汇总: " . \json_encode($summary['totals'], \JSON_UNESCAPED_UNICODE) . "\n";
            foreach ($filtered as $v) {
                echo \sprintf(
                    "  [%s][%s] %s:%d:%d  %s\n    %s\n",
                    $v['rule_id'],
                    $v['severity'],
                    $v['path'],
                    $v['line'],
                    $v['col'],
                    $v['message'],
                    $v['snippet']
                );
            }
            echo "[anti-hardcode-scan] 共 " . \count($filtered) . " 条命中。\n";
        }

        // MVP：只读扫描，不阻断 CI；未来接入 release_gate 时再改为非零退出码。
        return 0;
    }

    public function tip(): string
    {
        return 'AI 建站中台 · 反硬编码扫描（只读，T31 MVP）';
    }

    public function help(): array|string
    {
        return [
            'Usage: php bin/w ai-site:anti-hardcode:scan [--path=<abs>] [--format=text|json] [--severity=error|warning|info|all]',
            '  (alias of: php bin/w aisite:anti-hardcode-scan)',
            '',
            'Scans .phtml / .php / .js files for hard-coded Chinese literals, bare URLs, raw hex colors,',
            'native alert/confirm/prompt calls, untranslated throw-literals, console debug leftovers and',
            'forbidden declare(strict_types=1) in templates.',
            '',
            'Default path:',
            '  app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/',
        ];
    }

    /**
     * @param list<array{rule_id:string,severity:string}> $violations
     * @return list<array<string, mixed>>
     */
    private function filterBySeverity(array $violations, string $severity): array
    {
        if ($severity === '' || $severity === 'all') {
            return $violations;
        }
        $allowed = match ($severity) {
            'error'   => ['error'],
            'warning' => ['error', 'warning'],
            'info'    => ['error', 'warning', 'info'],
            default   => ['error', 'warning', 'info'],
        };
        $out = [];
        foreach ($violations as $v) {
            if (\in_array($v['severity'], $allowed, true)) {
                $out[] = $v;
            }
        }
        return $out;
    }
}
