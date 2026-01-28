<?php

declare(strict_types=1);

namespace WeShop\Filters\Model;

use WeShop\Filters\Api\FilterResultInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Manager\ObjectManager;

/**
 * 筛选缓存模型
 */
class FilterCache extends Model
{
    public const fields_cache_id = 'cache_id';
    public const fields_category_id = 'category_id';
    public const fields_filter_data = 'filter_data';
    public const fields_count_data = 'count_data';
    public const fields_created_at = 'created_at';
    public const fields_expires_at = 'expires_at';
    
    /**
     * @var array 主键
     */
    public array $_unit_primary_keys = ['cache_id'];
    
    /**
     * @var array 索引字段
     */
    public array $_index_sort_keys = ['cache_id', 'category_id', 'expires_at'];
    
    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }
    
    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
    }
    
    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('筛选缓存表')
                ->addColumn(
                    self::fields_cache_id,
                    TableInterface::column_type_VARCHAR,
                    64,
                    'primary key',
                    '缓存键'
                )
                ->addColumn(
                    self::fields_category_id,
                    TableInterface::column_type_INTEGER,
                    0,
                    'not null default 0',
                    '分类ID'
                )
                ->addColumn(
                    self::fields_filter_data,
                    TableInterface::column_type_LONG_TEXT,
                    0,
                    '',
                    '筛选数据JSON'
                )
                ->addColumn(
                    self::fields_count_data,
                    TableInterface::column_type_LONG_TEXT,
                    0,
                    '',
                    '计数数据JSON'
                )
                ->addColumn(
                    self::fields_created_at,
                    TableInterface::column_type_DATETIME,
                    0,
                    'default current_timestamp',
                    '创建时间'
                )
                ->addColumn(
                    self::fields_expires_at,
                    TableInterface::column_type_DATETIME,
                    0,
                    '',
                    '过期时间'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_category_id',
                    self::fields_category_id
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_expires_at',
                    self::fields_expires_at
                )
                ->create();
        }
    }
    
    /**
     * 获取缓存数据
     * 
     * @param string $cacheKey
     * @return FilterResultInterface|null
     */
    public function getCacheData(string $cacheKey): ?FilterResultInterface
    {
        $this->reset()
            ->where(self::fields_cache_id, $cacheKey)
            ->where(self::fields_expires_at, date('Y-m-d H:i:s'), '>');
        
        $data = $this->find()->fetchArray();
        
        if (empty($data)) {
            return null;
        }
        
        try {
            $filterData = json_decode($data[self::fields_filter_data] ?? '{}', true);
            
            /** @var FilterResult $result */
            $result = ObjectManager::getInstance(FilterResult::class);
            $result->setProductIds($filterData['product_ids'] ?? [])
                ->setFilters($filterData['filters'] ?? [])
                ->setAppliedFilters($filterData['applied_filters'] ?? [])
                ->setOriginalCount($filterData['original_count'] ?? 0)
                ->setClearAllUrl($filterData['clear_all_url'] ?? '');
            
            return $result;
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    /**
     * 设置缓存数据
     * 
     * @param string $cacheKey
     * @param FilterResultInterface $result
     * @param int $ttl 缓存时间（秒）
     * @return bool
     */
    public function setCacheData(string $cacheKey, FilterResultInterface $result, int $ttl = 3600): bool
    {
        // 从缓存键中提取 category_id
        $categoryId = 0;
        if (preg_match('/^weshop_filter_(\d+)_/', $cacheKey, $matches)) {
            $categoryId = (int)$matches[1];
        }
        
        $data = [
            self::fields_cache_id => $cacheKey,
            self::fields_category_id => $categoryId,
            self::fields_filter_data => json_encode($result->toArray()),
            self::fields_count_data => json_encode([]),
            self::fields_created_at => date('Y-m-d H:i:s'),
            self::fields_expires_at => date('Y-m-d H:i:s', time() + $ttl),
        ];
        
        try {
            $this->reset()->insert($data, [self::fields_cache_id])->fetch();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
    
    /**
     * 删除缓存数据
     * 
     * @param string $cacheKey
     * @return bool
     */
    public function deleteCacheData(string $cacheKey): bool
    {
        try {
            $this->reset()
                ->where(self::fields_cache_id, $cacheKey)
                ->delete()
                ->fetch();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
    
    /**
     * 清除分类的所有缓存
     * 
     * @param int $categoryId
     * @return bool
     */
    public function clearByCategoryId(int $categoryId): bool
    {
        try {
            $this->reset()
                ->where(self::fields_category_id, $categoryId)
                ->delete()
                ->fetch();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
    
    /**
     * 清除所有缓存
     * 
     * @return bool
     */
    public function clearAllCache(): bool
    {
        try {
            // 使用 DELETE 代替 TRUNCATE 以兼容 PostgreSQL
            // TRUNCATE 在框架中使用了 MySQL 特有语法
            $this->reset()
                ->where(self::fields_cache_id, '', '!=')
                ->delete()
                ->fetch();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
    
    /**
     * 清除过期缓存
     * 
     * @return int 清除的数量
     */
    public function clearExpiredCache(): int
    {
        try {
            $this->reset()
                ->where(self::fields_expires_at, date('Y-m-d H:i:s'), '<')
                ->delete()
                ->fetch();
            return $this->getAffectedRows();
        } catch (\Throwable $e) {
            return 0;
        }
    }
    
    /**
     * 获取影响的行数
     * 
     * @return int
     */
    private function getAffectedRows(): int
    {
        try {
            return (int)$this->getConnection()->rowCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
