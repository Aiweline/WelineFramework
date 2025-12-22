<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Interface\TrendPlatformAdapterInterface;

/**
 * 趋势获取服务
 * 
 * 统一调用各个趋势平台适配器获取关键词趋势数据
 * 
 * @package Weline_Seo
 */
class TrendFetcherService
{
    private ObjectManager $objectManager;
    private array $adapters = [];

    public function __construct(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * 获取所有趋势平台适配器
     * 
     * @return TrendPlatformAdapterInterface[]
     */
    private function getAdapters(): array
    {
        if (!empty($this->adapters)) {
            return $this->adapters;
        }

        // TODO: 从配置或自动发现中加载适配器
        // 这里先返回空数组，后续可以通过扩展机制自动发现
        
        return $this->adapters;
    }

    /**
     * 获取关键词趋势数据
     * 
     * @param array $keywords 关键词数组
     * @param array $options 选项（平台、地区等）
     * @return array 趋势数据，格式：['platform' => ['keyword' => trend_data]]
     */
    public function fetchTrends(array $keywords, array $options = []): array
    {
        $adapters = $this->getAdapters();
        $results = [];

        foreach ($adapters as $adapter) {
            $platform = $adapter->getCode();
            try {
                $trends = $adapter->fetchTrends($keywords, $options);
                $results[$platform] = $trends;
            } catch (\Exception $e) {
                // 记录错误，继续处理其他平台
                $results[$platform] = ['error' => $e->getMessage()];
            }
        }

        return $results;
    }
}

