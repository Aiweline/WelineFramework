<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2025/10/09
 */

namespace Weline\Ai\Console\Ai\Model;

use Weline\Ai\Service\Provider\ModelSyncService;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;

/**
 * AI模型同步CLI命令
 *
 * 功能：
 * - 调用供应商模型列表 API
 * - 更新供应商 models 配置
 * - 自动生成/更新 models 配置文件
 * - 可选触发模型收集
 */
class Sync extends CommandAbstract
{
    private ModelSyncService $modelSyncService;

    public function __construct(ModelSyncService $modelSyncService)
    {
        $this->modelSyncService = $modelSyncService;
    }

    public function tip(): string
    {
        return 'ai:model:sync 同步供应商模型列表并更新配置';
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'ai:model:sync',
            $this->tip(),
            [
                '-p, --provider <code>' => '指定供应商代码（可多次使用）',
                '--dry-run' => '仅模拟，不写入配置文件',
                '--no-collect' => '不同步到数据库（跳过模型收集）',
            ],
            [],
            [
                '同步所有供应商' => 'php bin/w ai:model:sync',
                '同步指定供应商' => 'php bin/w ai:model:sync -p openai -p anthropic',
                '干跑模式' => 'php bin/w ai:model:sync --dry-run',
                '只更新配置，不收集模型' => 'php bin/w ai:model:sync --no-collect',
            ]
        );
    }

    public function execute(array $args = [], array $data = [])
    {
        $providers = [];
        foreach ($args as $key => $value) {
            if ($key === 'p' || $key === 'provider') {
                if (is_array($value)) {
                    $providers = array_merge($providers, $value);
                } else {
                    $providers[] = $value;
                }
            }
        }

        $dryRun = isset($args['dry-run']) || isset($data['dry-run']);
        $collect = !(isset($args['no-collect']) || isset($data['no-collect']));

        $this->printer->note(__('开始同步AI模型列表...'));

        $options = [
            'providers' => !empty($providers) ? $providers : null,
            'dry_run' => $dryRun,
            'collect' => $collect,
        ];

        $result = $this->modelSyncService->syncAllProviders($options);
        $providersResult = $result['providers'] ?? [];

        foreach ($providersResult as $providerCode => $providerResult) {
            if (!empty($providerResult['success'])) {
                $count = $providerResult['count'] ?? 0;
                $updated = $providerResult['updated'] ?? 0;
                $created = $providerResult['created'] ?? 0;
                $skipped = $providerResult['skipped'] ?? 0;
                $this->printer->success(
                    __('%{1} 同步成功：模型 %{2}，更新 %{3}，新增 %{4}，跳过 %{5}', [
                        $providerCode, $count, $updated, $created, $skipped
                    ])
                );
            } else {
                $this->printer->warning(
                    __('%{1} 同步失败：%{2}', [
                        $providerCode, $providerResult['message'] ?? __('未知错误')
                    ])
                );
            }
        }

        if ($collect) {
            $collectedCount = $result['collected_count'] ?? 0;
            $this->printer->note(__('模型收集完成，共 %{1} 个模型', [$collectedCount]));
        } else {
            $this->printer->note(__('已跳过模型收集'));
        }
    }
}
