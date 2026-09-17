<?php

declare(strict_types=1);

namespace Weline\Search\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Search\Service\SearchProviderIndexService;
use Weline\Search\Service\SearchProviderRegistry;

/** Warm up empty provider indexes after setup:upgrade. */
final class SetupProviderIndexWarmupObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        /** @var SearchProviderIndexService $indexService */
        $indexService = ObjectManager::getInstance(SearchProviderIndexService::class);
        /** @var SearchProviderRegistry $registry */
        $registry = ObjectManager::getInstance(SearchProviderRegistry::class);

        $printing = null;
        if (PHP_SAPI === 'cli') {
            try {
                /** @var Printing $printing */
                $printing = ObjectManager::getInstance(Printing::class);
            } catch (\Throwable) {
                $printing = null;
            }
        }

        $providers = $registry->all();
        $printing?->note(__('开始预热搜索 Provider 索引（共 %{count} 个）…', [
            'count' => count($providers),
        ]));

        $rebuilt = 0;
        $skipped = 0;
        foreach ($providers as $code => $provider) {
            if ($indexService->isIndexed($code, 0)) {
                $skipped++;
                $printing?->note(__('搜索索引已存在，跳过：%{code}', ['code' => (string)$code]));
                continue;
            }
            $printing?->note(__('正在重建搜索索引：%{code}…', ['code' => (string)$code]));
            $indexService->rebuild($code, 0);
            $rebuilt++;
            $printing?->success(__('搜索索引重建完成：%{code}', ['code' => (string)$code]));
        }

        $printing?->success(__('搜索 Provider 索引预热完成：重建 %{rebuilt}，跳过 %{skipped}', [
            'rebuilt' => $rebuilt,
            'skipped' => $skipped,
        ]));
    }
}
