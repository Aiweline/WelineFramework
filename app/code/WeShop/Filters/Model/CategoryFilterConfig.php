<?php

declare(strict_types=1);

namespace WeShop\Filters\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 分类筛选配置模型
 *
 * 存储每个分类的筛选配置
 */
#[Table(comment: '分类筛选配置表')]
#[Index(name: 'idx_category_filter', columns: ['category_id', 'filter_code'], type: 'UNIQUE')]
#[Index(name: 'idx_category_id', columns: ['category_id'])]
#[Index(name: 'idx_attribute_id', columns: ['attribute_id'])]
class CategoryFilterConfig extends Model
{
    public const CONFIG_FACET_MODE = 'facet_mode';
    public const CONFIG_RANGE_BUCKETS = 'range_buckets';
    public const CONFIG_BUCKET_SIZE = 'bucket_size';

    public const schema_table = 'weshop_filters_category_filter_config';
    public const schema_primary_key = 'id';

    #[Col('integer', 0, primaryKey: true, autoIncrement: true, nullable: false, comment: '配置ID')]
    public const schema_fields_ID = 'id';
    #[Col('integer', 0, nullable: false, default: 0, comment: '分类ID，0表示全局配置')]
    public const schema_fields_CATEGORY_ID = 'category_id';
    #[Col('varchar', 64, nullable: false, comment: '筛选器代码')]
    public const schema_fields_FILTER_CODE = 'filter_code';
    #[Col('integer', 0, nullable: true, comment: 'EAV属性ID（仅用于属性筛选）')]
    public const schema_fields_ATTRIBUTE_ID = 'attribute_id';
    #[Col('integer', 0, nullable: true, default: 100, comment: '排序权重')]
    public const schema_fields_SORT_ORDER = 'sort_order';
    #[Col('smallint', 1, nullable: true, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ENABLED = 'is_enabled';
    #[Col('varchar', 32, nullable: true, default: 'list', comment: '显示类型：list/swatch/slider/checkbox/radio')]
    public const schema_fields_DISPLAY_TYPE = 'display_type';
    #[Col('smallint', 1, nullable: true, default: 0, comment: '默认折叠')]
    public const schema_fields_IS_COLLAPSED = 'is_collapsed';
    #[Col('smallint', 1, nullable: true, default: 1, comment: '是否继承父分类配置')]
    public const schema_fields_INHERIT_PARENT = 'inherit_parent';
    #[Col('text', 0, nullable: true, comment: '额外配置数据JSON')]
    public const schema_fields_CONFIG_DATA = 'config_data';

    /**
     * @var array 唯一键
     */
    public array $_unit_primary_keys = ['category_id', 'filter_code'];

    /**
     * @var array 索引字段
     */
    public array $_index_sort_keys = ['id', 'category_id', 'filter_code', 'attribute_id', 'sort_order'];

    /**
     * 获取分类的筛选配置
     */
    public function getFilterConfig(int $categoryId, ?string $filterCode = null): ?array
    {
        $this->reset();

        if ($filterCode !== null) {
            $this->where(self::schema_fields_CATEGORY_ID, $categoryId)
                ->where(self::schema_fields_FILTER_CODE, $filterCode);
            $result = $this->find()->fetchArray();
            return !empty($result) ? $this->normalizeConfigRow($result) : null;
        }

        $this->where(self::schema_fields_CATEGORY_ID, $categoryId)
            ->order(self::schema_fields_SORT_ORDER);
        return $this->normalizeConfigRows($this->select()->fetchArray());
    }

    /**
     * 获取分类的所有启用的筛选配置
     */
    public function getEnabledFilters(int $categoryId, bool $includeInherited = true): array
    {
        $configs = [];

        $this->reset()
            ->where(self::schema_fields_CATEGORY_ID, $categoryId)
            ->where(self::schema_fields_IS_ENABLED, 1)
            ->order(self::schema_fields_SORT_ORDER);
        $categoryConfigs = $this->normalizeConfigRows($this->select()->fetchArray());

        foreach ($categoryConfigs as $config) {
            $configs[$config[self::schema_fields_FILTER_CODE]] = $config;
        }

        if ($includeInherited) {
            $this->reset()
                ->where(self::schema_fields_CATEGORY_ID, 0)
                ->where(self::schema_fields_IS_ENABLED, 1)
                ->order(self::schema_fields_SORT_ORDER);
            $globalConfigs = $this->normalizeConfigRows($this->select()->fetchArray());

            foreach ($globalConfigs as $config) {
                $filterCode = $config[self::schema_fields_FILTER_CODE];
                if (!isset($configs[$filterCode])) {
                    $configs[$filterCode] = $config;
                }
            }
        }

        uasort($configs, function ($a, $b) {
            return ($a[self::schema_fields_SORT_ORDER] ?? 100) <=> ($b[self::schema_fields_SORT_ORDER] ?? 100);
        });

        return array_values($configs);
    }

    /**
     * 保存筛选配置
     */
    public function saveFilterConfig(int $categoryId, string $filterCode, array $data): bool
    {
        $this->reset();

        $data[self::schema_fields_CATEGORY_ID] = $categoryId;
        $data[self::schema_fields_FILTER_CODE] = $filterCode;

        if (isset($data[self::schema_fields_CONFIG_DATA]) && is_array($data[self::schema_fields_CONFIG_DATA])) {
            $data[self::schema_fields_CONFIG_DATA] = json_encode($data[self::schema_fields_CONFIG_DATA]);
        }

        try {
            $this->insert($data, [self::schema_fields_CATEGORY_ID, self::schema_fields_FILTER_CODE])->fetch();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalizeConfigRows(array $rows): array
    {
        return array_map(fn (array $row): array => $this->normalizeConfigRow($row), $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeConfigRow(array $row): array
    {
        $configData = $row[self::schema_fields_CONFIG_DATA] ?? [];

        if (is_string($configData)) {
            $decoded = json_decode($configData, true);
            $configData = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($configData)) {
            $configData = [];
        }

        $configData[self::CONFIG_FACET_MODE] = (string) ($configData[self::CONFIG_FACET_MODE] ?? 'terms');
        $configData[self::CONFIG_BUCKET_SIZE] = max(1, (int) ($configData[self::CONFIG_BUCKET_SIZE] ?? 20));
        $rangeBuckets = $configData[self::CONFIG_RANGE_BUCKETS] ?? [];
        $configData[self::CONFIG_RANGE_BUCKETS] = is_array($rangeBuckets) ? array_values($rangeBuckets) : [];

        $row[self::schema_fields_CONFIG_DATA] = $configData;

        return $row;
    }

    /**
     * 删除筛选配置
     */
    public function deleteFilterConfig(int $categoryId, ?string $filterCode = null): bool
    {
        $this->reset()
            ->where(self::schema_fields_CATEGORY_ID, $categoryId);

        if ($filterCode !== null) {
            $this->where(self::schema_fields_FILTER_CODE, $filterCode);
        }

        try {
            $this->delete()->fetch();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
