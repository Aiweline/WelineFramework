<?php
declare(strict_types=1);

namespace Weline\Ai\Cron;

use Weline\Ai\Service\Provider\ModelSyncService;
use Weline\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * AI模型自动同步任务
 */
class ModelSync implements CronTaskInterface
{
    public function name(): string
    {
        return 'AI模型自动同步';
    }

    public function execute_name(): string
    {
        return 'ai_model_sync';
    }

    public function tip(): string
    {
        return '定期同步供应商模型列表并自动收集到数据库';
    }

    public function cron_time(): string
    {
        // 每天凌晨 3:30 执行
        return '30 3 * * *';
    }

    public function execute(): string
    {
        try {
            /** @var ModelSyncService $syncService */
            $syncService = ObjectManager::getInstance(ModelSyncService::class);
            $result = $syncService->syncAllProviders([
                'collect' => true,
            ]);

            $providerSummary = [];
            foreach (($result['providers'] ?? []) as $providerCode => $providerResult) {
                if (!empty($providerResult['success'])) {
                    $providerSummary[] = sprintf(
                        '%s=%d',
                        $providerCode,
                        (int)($providerResult['count'] ?? 0)
                    );
                } else {
                    $providerSummary[] = sprintf('%s=failed', $providerCode);
                }
            }

            $collectedCount = (int)($result['collected_count'] ?? 0);
            return __('AI模型同步完成：%{1}；收集 %{2} 个模型', [
                implode(', ', $providerSummary),
                $collectedCount
            ]);
        } catch (\Throwable $e) {
            return __('AI模型同步失败：%{1}', [$e->getMessage()]);
        }
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return $minute;
    }
}
