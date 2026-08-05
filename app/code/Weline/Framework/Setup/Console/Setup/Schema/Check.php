<?php

declare(strict_types=1);

namespace Weline\Framework\Setup\Console\Setup\Schema;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Database\Schema\SchemaCheckpointDriftInspector;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

/**
 * Read-only Schema checkpoint drift check for local/CI use.
 * Exit: 0 clean, 1 drift, 2 environment/runtime failure.
 */
class Check extends CommandAbstract
{
    public function __construct(
        private readonly Printing $printing,
    ) {
    }

    public function execute(array $args = [], array $data = []): int
    {
        if (isset($args['h']) || isset($args['help']) || isset($args['-h']) || isset($args['--help'])) {
            $help = $this->help();
            echo is_string($help) ? $help : (string)json_encode($help, JSON_UNESCAPED_UNICODE);
            return 0;
        }

        $json = isset($args['json']) || isset($args['j']);
        $modules = $this->parseModuleArgs($args);
        $moduleFilter = $modules === [] ? null : $modules;

        try {
            /** @var SchemaCheckpointDriftInspector $inspector */
            $inspector = ObjectManager::getInstance(SchemaCheckpointDriftInspector::class);
            $report = $inspector->inspect($moduleFilter);
        } catch (\Throwable $e) {
            if ($json) {
                echo (string)json_encode([
                    'ok' => false,
                    'exit' => 2,
                    'error' => $e->getMessage(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
            } else {
                $this->printing->error(__('setup:schema:check 失败：%{1}', [$e->getMessage()]));
            }

            return 2;
        }

        if ($json) {
            echo (string)json_encode([
                'ok' => $report['clean'],
                'exit' => $report['clean'] ? 0 : 1,
                'checked_modules' => $report['checked_modules'],
                'drifts' => $report['drifts'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;

            return $report['clean'] ? 0 : 1;
        }

        $this->printing->note(__(
            'Schema checkpoint 检查：已比对 %{1} 个模块',
            [$report['checked_modules']]
        ));
        if ($report['clean']) {
            $this->printing->success(__('无 Schema checkpoint 漂移'));
            return 0;
        }

        foreach ($report['drifts'] as $drift) {
            $tables = array_slice(array_values(array_unique(array_merge(
                $drift['changed_tables'],
                $drift['added_tables'],
                $drift['removed_tables'],
            ))), 0, 20);
            $suggest = $drift['suggested_version'] ?? null;
            $this->printing->error(__(
                '漂移：%{1}@%{2} 表=%{3} 建议 version=%{4}',
                [
                    $drift['module'],
                    $drift['version'],
                    $tables === [] ? '(checksum)' : implode(',', $tables),
                    $suggest ?? __('请手改 etc/module.php version'),
                ]
            ));
        }
        $this->printing->error(__('发现 %{1} 个模块 Schema checkpoint 漂移', [count($report['drifts'])]));

        return 1;
    }

    public function tip(): string
    {
        return __('只读检测声明式 Model 指纹与已存 Schema checkpoint 是否漂移（无 DDL）');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'setup:schema:check',
            $this->tip(),
            [
                '-m, --module=<模块名>' => __('仅检查指定模块'),
                '--json' => __('JSON 输出'),
                '-h, --help' => __('显示帮助信息'),
            ],
            [],
            [
                __('全量检查') => 'php bin/w setup:schema:check',
                __('指定模块 JSON') => 'php bin/w setup:schema:check -m Weline_DeveloperWorkspace --json',
            ],
            'php bin/w setup:schema:check [-m|--module=<模块名>] [--json]'
        );
    }
}
