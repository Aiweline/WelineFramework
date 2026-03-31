<?php
declare(strict_types=1);

namespace WeShop\Promotion\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 促销活动模型
 */
#[Table(comment: 'WeShop促销活动表')]
#[Index(name: 'idx_name', columns: ['name'], type: 'FULLTEXT', comment: '活动名称索引')]
#[Index(name: 'idx_type', columns: ['type'], type: 'DEFAULT', comment: '活动类型索引')]
#[Index(name: 'idx_status', columns: ['status'], type: 'DEFAULT', comment: '状态索引')]
#[Index(name: 'idx_start_date', columns: ['start_date'], type: 'DEFAULT', comment: '开始日期索引')]
#[Index(name: 'idx_end_date', columns: ['end_date'], type: 'DEFAULT', comment: '结束日期索引')]
class Campaign extends Model
{
    public const schema_table = 'weshop_campaign';
    public const schema_primary_key = 'campaign_id';
    public string $indexer = 'campaign_indexer';

    public const schema_fields_ID = 'campaign_id';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '活动名称')]
    public const schema_fields_NAME = 'name';
    #[Col(type: 'varchar', length: 20, nullable: true, default: 'promotion', comment: '活动类型（promotion/flash/seasonal）')]
    public const schema_fields_TYPE = 'type';
    #[Col(type: 'text', nullable: true, comment: '活动描述')]
    public const schema_fields_DESCRIPTION = 'description';
    #[Col(type: 'varchar', length: 20, nullable: true, default: 'fixed', comment: '折扣类型（fixed/percent/buy_x_get_y）')]
    public const schema_fields_DISCOUNT_TYPE = 'discount_type';
    #[Col(type: 'decimal', length: '10,2', nullable: false, default: '0.00', comment: '折扣值')]
    public const schema_fields_DISCOUNT_VALUE = 'discount_value';
    #[Col(type: 'decimal', length: '10,2', nullable: false, default: '0.00', comment: '最低消费金额')]
    public const schema_fields_MIN_PURCHASE = 'min_purchase';
    #[Col(type: 'decimal', length: '10,2', nullable: true, default: '0.00', comment: '最大优惠金额')]
    public const schema_fields_MAX_DISCOUNT = 'max_discount';
    #[Col(type: 'datetime', nullable: true, comment: '开始时间')]
    public const schema_fields_START_DATE = 'start_date';
    #[Col(type: 'datetime', nullable: true, comment: '结束时间')]
    public const schema_fields_END_DATE = 'end_date';
    #[Col(type: 'text', nullable: true, comment: '参与商品ID列表，逗号分隔')]
    public const schema_fields_PRODUCT_IDS = 'product_ids';
    #[Col(type: 'text', nullable: true, comment: '参与分类ID列表，逗号分隔')]
    public const schema_fields_CATEGORY_IDS = 'category_ids';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 1, comment: '状态：1启用 0禁用')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 0, comment: '是否精选推荐')]
    public const schema_fields_IS_FEATURED = 'is_featured';
    #[Col(type: 'int', nullable: false, default: 0, comment: '优先级')]
    public const schema_fields_PRIORITY = 'priority';
    #[Col(type: 'datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: false, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['campaign_id'];
    public array $_index_sort_keys = ['campaign_id', 'type', 'status', 'start_date', 'end_date'];

    /**
     * 获取活动名称
     */
    public function getName(): string
    {
        return (string) $this->getData(self::schema_fields_NAME);
    }

    /**
     * 设置活动名称
     */
    public function setName(string $name): static
    {
        return $this->setData(self::schema_fields_NAME, $name);
    }

    /**
     * 获取活动类型
     */
    public function getType(): string
    {
        return (string) ($this->getData(self::schema_fields_TYPE) ?: 'promotion');
    }

    /**
     * 设置活动类型
     */
    public function setType(string $type): static
    {
        return $this->setData(self::schema_fields_TYPE, $type);
    }

    /**
     * 获取活动描述
     */
    public function getDescription(): string
    {
        return (string) $this->getData(self::schema_fields_DESCRIPTION);
    }

    /**
     * 设置活动描述
     */
    public function setDescription(string $description): static
    {
        return $this->setData(self::schema_fields_DESCRIPTION, $description);
    }

    /**
     * 获取折扣类型
     */
    public function getDiscountType(): string
    {
        return (string) ($this->getData(self::schema_fields_DISCOUNT_TYPE) ?: 'fixed');
    }

    /**
     * 设置折扣类型
     */
    public function setDiscountType(string $discountType): static
    {
        return $this->setData(self::schema_fields_DISCOUNT_TYPE, $discountType);
    }

    /**
     * 获取折扣值
     */
    public function getDiscountValue(): float
    {
        return (float) ($this->getData(self::schema_fields_DISCOUNT_VALUE) ?: 0);
    }

    /**
     * 设置折扣值
     */
    public function setDiscountValue(float $discountValue): static
    {
        return $this->setData(self::schema_fields_DISCOUNT_VALUE, $discountValue);
    }

    /**
     * 获取最低消费
     */
    public function getMinPurchase(): float
    {
        return (float) ($this->getData(self::schema_fields_MIN_PURCHASE) ?: 0);
    }

    /**
     * 设置最低消费
     */
    public function setMinPurchase(float $minPurchase): static
    {
        return $this->setData(self::schema_fields_MIN_PURCHASE, $minPurchase);
    }

    /**
     * 获取最大优惠
     */
    public function getMaxDiscount(): float
    {
        return (float) ($this->getData(self::schema_fields_MAX_DISCOUNT) ?: 0);
    }

    /**
     * 设置最大优惠
     */
    public function setMaxDiscount(float $maxDiscount): static
    {
        return $this->setData(self::schema_fields_MAX_DISCOUNT, $maxDiscount);
    }

    /**
     * 获取开始时间
     */
    public function getStartDate(): ?string
    {
        return $this->getData(self::schema_fields_START_DATE);
    }

    /**
     * 设置开始时间
     */
    public function setStartDate(?string $startDate): static
    {
        return $this->setData(self::schema_fields_START_DATE, $startDate);
    }

    /**
     * 获取结束时间
     */
    public function getEndDate(): ?string
    {
        return $this->getData(self::schema_fields_END_DATE);
    }

    /**
     * 设置结束时间
     */
    public function setEndDate(?string $endDate): static
    {
        return $this->setData(self::schema_fields_END_DATE, $endDate);
    }

    /**
     * 获取参与商品ID列表
     */
    public function getProductIds(): array
    {
        $ids = $this->getData(self::schema_fields_PRODUCT_IDS);
        if (empty($ids)) {
            return [];
        }
        return array_filter(array_map('intval', explode(',', $ids)));
    }

    /**
     * 设置参与商品ID列表
     */
    public function setProductIds(array $productIds): static
    {
        return $this->setData(self::schema_fields_PRODUCT_IDS, implode(',', $productIds));
    }

    /**
     * 获取参与分类ID列表
     */
    public function getCategoryIds(): array
    {
        $ids = $this->getData(self::schema_fields_CATEGORY_IDS);
        if (empty($ids)) {
            return [];
        }
        return array_filter(array_map('intval', explode(',', $ids)));
    }

    /**
     * 设置参与分类ID列表
     */
    public function setCategoryIds(array $categoryIds): static
    {
        return $this->setData(self::schema_fields_CATEGORY_IDS, implode(',', $categoryIds));
    }

    /**
     * 获取状态
     */
    public function getStatus(): int
    {
        return (int) ($this->getData(self::schema_fields_STATUS) ?: 0);
    }

    /**
     * 设置状态
     */
    public function setStatus(int $status): static
    {
        return $this->setData(self::schema_fields_STATUS, $status);
    }

    /**
     * 是否精选推荐
     */
    public function isFeatured(): bool
    {
        return (bool) ($this->getData(self::schema_fields_IS_FEATURED) ?? false);
    }

    /**
     * 设置精选推荐
     */
    public function setIsFeatured(bool $isFeatured): static
    {
        return $this->setData(self::schema_fields_IS_FEATURED, $isFeatured ? 1 : 0);
    }

    /**
     * 获取优先级
     */
    public function getPriority(): int
    {
        return (int) ($this->getData(self::schema_fields_PRIORITY) ?? 0);
    }

    /**
     * 设置优先级
     */
    public function setPriority(int $priority): static
    {
        return $this->setData(self::schema_fields_PRIORITY, $priority);
    }

    /**
     * 获取活动状态的文字描述
     */
    public function getStatusLabel(): string
    {
        $now = time();
        $startTs = $this->getStartDate() ? strtotime($this->getStartDate()) : 0;
        $endTs = $this->getEndDate() ? strtotime($this->getEndDate()) : PHP_INT_MAX;

        if ($endTs < $now) {
            return __('已结束');
        } elseif ($startTs > $now) {
            return __('未开始');
        } else {
            return __('进行中');
        }
    }

    /**
     * 检查活动是否有效
     */
    public function isValid(): bool
    {
        if (!$this->getStatus()) {
            return false;
        }

        $now = time();
        $startTs = $this->getStartDate() ? strtotime($this->getStartDate()) : 0;
        $endTs = $this->getEndDate() ? strtotime($this->getEndDate()) : PHP_INT_MAX;

        return $now >= $startTs && $now <= $endTs;
    }
}
