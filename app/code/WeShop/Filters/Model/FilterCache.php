<?php

declare(strict_types=1);

namespace WeShop\Filters\Model;

use WeShop\Filters\Api\FilterResultInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Manager\ObjectManager;
/**
 * 筛选缓存模型
 */
#[Table(comment: '筛选缓存表')]
#[Index(name: 'idx_category_id', columns: ['category_id'], comment: '分类ID索引')]
#[Index(name: 'idx_expires_at', columns: ['expires_at'], comment: '过期时间索引')]
class FilterCache extends Model
{
    public const schema_table = 'weshop_filters_filter_cache';
    public const schema_primary_key = 'cache_id';

    #[Col(type: 'varchar', length: 64, primaryKey: true, nullable: false, comment: '缓存键')]
    public const schema_fields_ID = 'cache_id';
    public const schema_fields_cache_id = 'cache_id';
    #[Col(type: 'int', nullable: false, default: 0, comment: '分类ID')]
    public const schema_fields_category_id = 'category_id';
    #[Col(type: 'longtext', nullable: true, comment: '筛选数据JSON')]
    public const schema_fields_filter_data = 'filter_data';
    #[Col(type: 'longtext', nullable: true, comment: '计数数据JSON')]
    public const schema_fields_count_data = 'count_data';
    #[Col(type: 'datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_created_at = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: '过期时间')]
    public const schema_fields_expires_at = 'expires_at';

    public array $_unit_primary_keys = ['cache_id'];
    public array $_index_sort_keys = ['cache_id', 'category_id', 'expires_at'];

    /**
     * 获取缓存数据
     */
    public function getCacheData(string $cacheKey): ?FilterResultInterface
    {
        $this->reset()
            ->where(self::schema_fields_cache_id, $cacheKey)
            ->where(self::schema_fields_expires_at, date('Y-m-d H:i:s'), '>');

        $data = $this->find()->fetchArray();

        if (empty($data)) {
            return null;
        }

        try {
            $filterData = json_decode($data[self::schema_fields_filter_data] ?? '{}', true);

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
     */
    public function setCacheData(string $cacheKey, FilterResultInterface $result, int $ttl = 3600): bool
    {
        $categoryId = 0;
        if (preg_match('/^weshop_filter_(\d+)_/', $cacheKey, $matches)) {
            $categoryId = (int)$matches[1];
        }

        $data = [
            self::schema_fields_cache_id => $cacheKey,
            self::schema_fields_category_id => $categoryId,
            self::schema_fields_filter_data => json_encode($result->toArray()),
            self::schema_fields_count_data => json_encode([]),
            self::schema_fields_created_at => date('Y-m-d H:i:s'),
            self::schema_fields_expires_at => date('Y-m-d H:i:s', time() + $ttl),
        ];

        try {
            $this->reset()->insert($data, [self::schema_fields_cache_id])->fetch();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 删除缓存数据
     */
    public function deleteCacheData(string $cacheKey): bool
    {
        try {
            $this->reset()
                ->where(self::schema_fields_cache_id, $cacheKey)
                ->delete()
                ->fetch();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 清除分类的所有缓存
     */
    public function clearByCategoryId(int $categoryId): bool
    {
        try {
            $this->reset()
                ->where(self::schema_fields_category_id, $categoryId)
                ->delete()
                ->fetch();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 清除所有缓存
     */
    public function clearAllCache(): bool
    {
        try {
            $this->reset()
                ->where(self::schema_fields_cache_id, '', '!=')
                ->delete()
                ->fetch();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 清除过期缓存
     */
    public function clearExpiredCache(): int
    {
        try {
            $this->reset()
                ->where(self::schema_fields_expires_at, date('Y-m-d H:i:s'), '<')
                ->delete()
                ->fetch();
            return $this->getAffectedRows();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function getAffectedRows(): int
    {
        try {
            return (int)$this->getConnection()->rowCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
