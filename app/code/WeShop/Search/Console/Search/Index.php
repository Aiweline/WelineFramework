<?php

declare(strict_types=1);

namespace WeShop\Search\Console\Search;

use WeShop\Search\Service\SearchIndexer;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Manager\ObjectManager;

class Index extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): void
    {
        $provider = isset($args['provider']) ? trim((string) $args['provider']) : '';
        $entityId = isset($args['entity_id']) ? (int) $args['entity_id'] : null;
        $productId = isset($args['product_id']) ? (int) $args['product_id'] : null;
        $forceReindex = isset($args['force']) || isset($args['f']);
        $configure = isset($args['configure']) || isset($args['c']);

        if ($productId !== null && $productId > 0) {
            $provider = 'product';
            $entityId = $productId;
        }

        /** @var SearchIndexer $indexer */
        $indexer = ObjectManager::getInstance(SearchIndexer::class);

        if ($configure) {
            $this->printer->note('正在写入搜索索引配置...');
            if (!$indexer->configure()) {
                $this->printer->error('搜索索引配置失败。');
                return;
            }
            $this->printer->success('搜索索引配置成功。');
        }

        if ($entityId !== null && $entityId > 0) {
            if ($provider === '') {
                $this->printer->error('单实体索引时必须指定 --provider。');
                return;
            }

            $this->printer->note(sprintf('正在同步 %s 实体 %d 到搜索索引...', $provider, $entityId));
            if ($indexer->indexEntity($provider, $entityId)) {
                $this->printer->success('实体索引同步成功。');
            } else {
                $this->printer->error('实体索引同步失败。');
            }
            return;
        }

        $targetLabel = $provider !== '' ? $provider : '全部 provider';
        $this->printer->note($forceReindex
            ? sprintf('正在强制重建 %s 搜索索引...', $targetLabel)
            : sprintf('正在构建 %s 搜索索引...', $targetLabel)
        );

        if ($indexer->rebuild($provider !== '' ? $provider : null, $forceReindex)) {
            $this->printer->success('搜索索引构建完成。');
        } else {
            $this->printer->error('搜索索引构建失败。');
        }
    }

    public function tip(): string
    {
        return '构建 OpenSearch / Elasticsearch / Meilisearch 搜索索引';
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'search:index',
            '重建或同步搜索索引',
            [
                '-h, --help' => '显示帮助信息',
                '--provider=CODE' => '指定 provider，例如 product、category',
                '--entity_id=ID' => '仅同步单个实体',
                '--product_id=ID' => '兼容旧参数，等价于 --provider=product --entity_id=ID',
                '-f, --force' => '强制重建指定 provider 的索引数据',
                '-c, --configure' => '仅写入索引配置（字段、映射、排序等）',
            ],
            [],
            [
                '重建全部 provider' => 'php bin/w search:index --force',
                '仅重建商品索引' => 'php bin/w search:index --provider=product --force',
                '仅重建分类索引' => 'php bin/w search:index --provider=category --force',
                '同步单个商品' => 'php bin/w search:index --provider=product --entity_id=1',
                '兼容旧用法' => 'php bin/w search:index --product_id=1',
                '仅写入索引配置' => 'php bin/w search:index --configure',
            ]
        );
    }
}
