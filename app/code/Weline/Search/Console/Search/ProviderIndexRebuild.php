<?php

declare(strict_types=1);

namespace Weline\Search\Console\Search;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Search\Service\SearchProviderIndexService;

final class ProviderIndexRebuild extends CommandAbstract
{
    /** @var list<string> */
    public const ALIASES = [
        'search:provider-index:rebuild',
    ];

    public function execute(array $args = [], array $data = []): int
    {
        /** @var Printing $printing */
        $printing = ObjectManager::getInstance(Printing::class);
        /** @var SearchProviderIndexService $service */
        $service = ObjectManager::getInstance(SearchProviderIndexService::class);

        $indexer = trim((string)($args['indexer'] ?? $args['i'] ?? ''));
        $websiteId = max(0, (int)($args['website_id'] ?? $args['w'] ?? 0));

        if ($indexer !== '') {
            $count = $service->rebuild($indexer, $websiteId);
            $printing->success(__('Provider 索引已重建：%{1}（website_id=%{2}，文档 %{3} 条）', [
                $indexer,
                (string)$websiteId,
                (string)$count,
            ]));

            return 0;
        }

        $count = $service->rebuildAll($websiteId);
        $printing->success(__('全部 Provider 索引已重建（website_id=%{1}，文档 %{2} 条）', [
            (string)$websiteId,
            (string)$count,
        ]));

        return 0;
    }

    public function tip(): string
    {
        return __('重建 Search Provider 内容索引（Blog 等非 Product 类型）');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'search:provider-index:rebuild',
            $this->tip(),
            [
                '--indexer, -i' => __('仅重建指定 provider code（如 blog）'),
                '--website_id, -w' => __('Website 维度，默认 0'),
                '-h, --help' => __('显示帮助'),
            ],
            [
                __('重建全部 Provider 索引') => 'php bin/w search:provider-index:rebuild',
                __('仅重建 Blog 索引') => 'php bin/w search:provider-index:rebuild -i blog',
            ],
        );
    }
}
