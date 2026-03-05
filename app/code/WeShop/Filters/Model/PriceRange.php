<?php
declare(strict_types=1);
namespace WeShop\Filters\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * 价格区间配置模型
 */
#[Table(comment: '价格区间配置表')]
#[Index(name: 'idx_category_id', columns: ['category_id'])]
class PriceRange extends Model
{
    public const schema_table = 'weshop_filters_price_range';
    public const schema_primary_key = 'id';
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '区间ID')]
    public const schema_fields_ID = 'id';
    #[Col(type: 'int', nullable: false, default: 0, comment: '分类ID，0表示全局')]
    public const schema_fields_category_id = 'category_id';
    #[Col(type: 'decimal', length: '12,4', nullable: false, default: 0, comment: '最低价')]
    public const schema_fields_min_price = 'min_price';
    #[Col(type: 'decimal', length: '12,4', nullable: true, comment: '最高价，null表示无上限')]
    public const schema_fields_max_price = 'max_price';
    #[Col(type: 'varchar', length: 128, nullable: true, comment: '显示标签')]
    public const schema_fields_label = 'label';
    #[Col(type: 'int', nullable: true, default: 0, comment: '排序')]
    public const schema_fields_sort_order = 'sort_order';
    #[Col(type: 'smallint', length: 1, nullable: true, default: 1, comment: '是否启用')]
    public const schema_fields_is_enabled = 'is_enabled';
    /** @var array 索引字段 */
    public array $_index_sort_keys = ['id', 'category_id', 'sort_order'];

    /**
     * 获取分类的价格区间
     */
    public function getPriceRanges(int $categoryId): array
    {
        $this->reset()
            ->where(self::schema_fields_category_id, $categoryId)
            ->where(self::schema_fields_is_enabled, 1)
            ->order(self::schema_fields_sort_order);
        $categoryRanges = $this->select()->fetchArray();
        if (!empty($categoryRanges)) {
            return $categoryRanges;
        }
        $this->reset()
            ->where(self::schema_fields_category_id, 0)
            ->where(self::schema_fields_is_enabled, 1)
            ->order(self::schema_fields_sort_order);
        return $this->select()->fetchArray();
    }
    /**
     * 保存价格区间
     */
    public function savePriceRange(
        int $categoryId,
        float $minPrice,
        ?float $maxPrice,
        string $label = '',
        int $sortOrder = 0
    ): bool {
        $data = [
            self::schema_fields_category_id => $categoryId,
            self::schema_fields_min_price => $minPrice,
            self::schema_fields_max_price => $maxPrice,
            self::schema_fields_label => $label,
            self::schema_fields_sort_order => $sortOrder,
            self::schema_fields_is_enabled => 1,
        ];
        try {
            $this->reset()->insert($data)->fetch();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
    /**
     * 批量保存价格区间
     */
    public function savePriceRanges(int $categoryId, array $ranges): bool
    {
        $this->deletePriceRanges($categoryId);
        $sortOrder = 0;
        foreach ($ranges as $range) {
            $minPrice = (float)($range['min'] ?? $range[0] ?? 0);
            $maxPrice = isset($range['max']) || isset($range[1])
                ? (float)($range['max'] ?? $range[1])
                : null;
            $label = $range['label'] ?? $range[2] ?? '';
            $this->savePriceRange($categoryId, $minPrice, $maxPrice, $label, $sortOrder++);
        }
        return true;
    }
    /**
     * 删除分类的价格区间
     */
    public function deletePriceRanges(int $categoryId): bool
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
     * 生成价格区间标签
     */
    public static function generateLabel(float $minPrice, ?float $maxPrice, string $currencySymbol = ''): string
    {
        if ($maxPrice === null) {
            $suffix = __('及以上');
            return $currencySymbol . number_format($minPrice, 0) . ' ' . $suffix;
        }
        if ($minPrice == 0) {
            $prefix = __('低于');
            return $prefix . ' ' . $currencySymbol . number_format($maxPrice, 0);
        }
        return $currencySymbol . number_format($minPrice, 0) . ' - ' . $currencySymbol . number_format($maxPrice, 0);
    }
}
