<?php

declare(strict_types=1);

namespace Weline\Deploy\Console\Deploy;

use Weline\Deploy\Service\DeployMachinePlanService;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;

/**
 * Public, read-only machine bridge used by Weline_Ai MCP.
 */
class Plan extends CommandAbstract
{
    public function __construct(
        private readonly DeployMachinePlanService $planService,
    ) {
    }

    public function tip(): string
    {
        return __('生成不执行发布的机器可读部署计划');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'deploy:plan',
            $this->tip(),
            [
                '--json' => '只输出 JSON（供 MCP 和自动化读取）',
                '--operation=<操作>' => 'config / preflight / release',
                '--target=<目标>' => 'local / staging / production',
                '--ref-type=<类型>' => '发布时必填：commit / tag',
                '--ref=<ref>' => '发布时必填：commit SHA 或 tag',
                '--base-url=<URL>' => '目标站点 HTTPS 基础地址',
                '-h, --help' => '显示帮助信息',
            ],
            [
                '安全边界' => '本命令仅读：不保存配置、不写仓库、不调用真实发布编排器。',
            ],
            [
                '本地检查' => 'php bin/w deploy:plan --json --target=local --operation=preflight',
                '预发预检' => 'php bin/w deploy:plan --json --target=staging --operation=preflight --base-url=https://staging.example.com',
                '生产计划' => 'php bin/w deploy:plan --json --target=production --operation=release --ref-type=tag --ref=v1.2.3 --base-url=https://example.com',
            ],
            'php bin/w deploy:plan --json --operation=<config|preflight|release> --target=<local|staging|production>',
        );
    }

    public function execute(array $args = [], array $data = []): int
    {
        try {
            $result = $this->planService->build([
                'operation' => $args['operation'] ?? 'preflight',
                'target' => $args['target'] ?? 'local',
                'ref_type' => $args['ref-type'] ?? $args['ref_type'] ?? '',
                'ref' => $args['ref'] ?? '',
                'base_url' => $args['base-url'] ?? $args['base_url'] ?? '',
            ]);
            $exitCode = in_array((string)($result['status'] ?? ''), ['ready', 'not_applicable'], true) ? 0 : 2;
        } catch (\Throwable $exception) {
            $result = [
                'schema_version' => 'deploy-machine-plan.v1',
                'status' => 'blocked',
                'development_blocked' => false,
                'deployment_blocked' => true,
                'release_executed' => false,
                'orchestrator_called' => false,
                'blockers' => [[
                    'code' => 'PLAN_BUILD_FAILED',
                    'message' => $exception->getMessage(),
                ]],
            ];
            $exitCode = 2;
        }

        $json = json_encode(
            $result,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        );
        fwrite(STDOUT, $json . PHP_EOL);

        return $exitCode;
    }
}
