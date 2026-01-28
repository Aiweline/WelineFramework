<?php

declare(strict_types=1);

namespace WeShop\Search\Console\Search;

use WeShop\Search\Service\ProductIndexer;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Manager\ObjectManager;

/**
 * 搜索索引命令
 * 用于手动索引产品数据到 Meilisearch
 */
class Index extends CommandAbstract
{

    public function execute(array $args = [], array $data = []): void
    {
        $productId = isset($args['product_id']) ? (int)$args['product_id'] : null;
        $forceReindex = isset($args['force']) || isset($args['f']);
        $configure = isset($args['configure']) || isset($args['c']);
        
        /** @var ProductIndexer $indexer */
        $indexer = ObjectManager::getInstance(ProductIndexer::class);
        
        // 配置索引设置
        if ($configure) {
            $this->printer->note('正在配置 Meilisearch 索引设置...');
            if ($indexer->configureIndex()) {
                $this->printer->success('索引配置成功！');
            } else {
                $this->printer->error('索引配置失败！');
                return;
            }
        }
        
        // 执行索引
        if ($productId !== null) {
            $this->printer->note("正在索引产品 ID: {$productId}...");
            if ($indexer->indexProduct($productId)) {
                $this->printer->success("产品 {$productId} 索引成功！");
            } else {
                $this->printer->error("产品 {$productId} 索引失败！");
            }
        } else {
            $this->printer->note($forceReindex ? '正在重新索引所有产品...' : '正在索引所有产品...');
            if ($indexer->indexProduct(null, $forceReindex)) {
                $this->printer->success('所有产品索引成功！');
            } else {
                $this->printer->error('产品索引失败！');
            }
        }
    }

    public function tip(): string
    {
        return '索引产品数据到 Meilisearch 搜索引擎';
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'search:index',
            '索引产品数据到 Meilisearch 搜索引擎',
            [
                '-h, --help' => '显示帮助信息',
                '--product_id=ID' => '指定产品ID，只索引该产品',
                '-f, --force' => '强制重新索引（删除所有现有索引）',
                '-c, --configure' => '配置索引设置（搜索字段、过滤字段等）',
            ],
            [],
            [
                '索引所有产品' => 'php bin/w search:index',
                '索引指定产品' => 'php bin/w search:index --product_id=1',
                '强制重新索引' => 'php bin/w search:index --force',
                '配置索引设置' => 'php bin/w search:index --configure',
                '完整操作' => 'php bin/w search:index --configure --force',
            ]
        );
    }
}
