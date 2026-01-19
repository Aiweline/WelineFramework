<?php

declare(strict_types=1);

namespace WeShop\Search\Api;

/**
 * 搜索引擎接口
 * 所有搜索引擎适配器必须实现此接口
 */
interface SearchEngineInterface
{
    /**
     * 搜索产品
     * 
     * @param string $keyword 关键词
     * @param array $filters 过滤条件
     * @param int $page 页码
     * @param int $pageSize 每页数量
     * @return array 返回格式: ['items' => [], 'total' => 0]
     */
    public function search(string $keyword, array $filters = [], int $page = 1, int $pageSize = 20): array;
    
    /**
     * 获取搜索建议
     * 
     * @param string $keyword 关键词
     * @param int $limit 返回数量
     * @return array
     */
    public function getSuggestions(string $keyword, int $limit = 10): array;
    
    /**
     * 初始化配置
     * 
     * @param array $config 配置数据
     * @return bool
     */
    public function initConfig(array $config): bool;
    
    /**
     * 测试连接
     * 
     * @return bool
     */
    public function testConnection(): bool;
    
    /**
     * 获取引擎类型
     * 
     * @return string
     */
    public function getEngineType(): string;
    
    /**
     * 获取引擎名称
     * 
     * @return string
     */
    public function getEngineName(): string;
}
