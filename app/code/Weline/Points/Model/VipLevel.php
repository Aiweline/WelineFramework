<?php

declare(strict_types=1);

namespace Weline\Points\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * VIP level definition. Levels are platform defaults, not site copy.
 */
#[Table(comment: 'Points VIP level definition')]
#[Index(name: 'uk_points_vip_level_code', columns: ['code'], type: 'UNIQUE')]
#[Index(name: 'idx_points_vip_level_growth', columns: ['is_active', 'min_growth'])]
class VipLevel extends Model
{
    public const schema_table = 'weline_points_vip_level';
    public const schema_primary_key = 'level_id';

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Level ID')]
    public const schema_fields_ID = 'level_id';

    #[Col('varchar', 32, nullable: false, comment: 'Stable level code')]
    public const schema_fields_CODE = 'code';

    #[Col('varchar', 64, nullable: false, comment: 'Display name')]
    public const schema_fields_NAME = 'name';

    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Minimum growth value')]
    public const schema_fields_MIN_GROWTH = 'min_growth';

    #[Col('decimal', '5,4', nullable: false, default: '0.0000', comment: 'Member discount rate, 0.05 = 5%')]
    public const schema_fields_DISCOUNT_RATE = 'discount_rate';

    #[Col('decimal', '6,3', nullable: false, default: '1.000', comment: 'Points earning multiplier')]
    public const schema_fields_POINTS_MULTIPLIER = 'points_multiplier';

    #[Col('smallint', 1, nullable: false, default: 0, comment: 'Free shipping benefit')]
    public const schema_fields_FREE_SHIPPING = 'free_shipping';

    #[Col('smallint', 1, nullable: false, default: 1, comment: 'Active flag')]
    public const schema_fields_IS_ACTIVE = 'is_active';

    #[Col('int', 11, nullable: false, default: 0, comment: 'Sort order')]
    public const schema_fields_SORT_ORDER = 'sort_order';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    /**
     * @return array<string, mixed>
     */
    public function toDefinition(): array
    {
        return [
            'level_id' => (int) $this->getData(self::schema_fields_ID),
            'code' => (string) $this->getData(self::schema_fields_CODE),
            'name' => (string) $this->getData(self::schema_fields_NAME),
            'min_growth' => (int) $this->getData(self::schema_fields_MIN_GROWTH),
            'discount_rate' => (float) $this->getData(self::schema_fields_DISCOUNT_RATE),
            'points_multiplier' => (float) $this->getData(self::schema_fields_POINTS_MULTIPLIER),
            'free_shipping' => (int) $this->getData(self::schema_fields_FREE_SHIPPING) === 1,
            'is_active' => (int) $this->getData(self::schema_fields_IS_ACTIVE) === 1,
            'sort_order' => (int) $this->getData(self::schema_fields_SORT_ORDER),
        ];
    }
}
