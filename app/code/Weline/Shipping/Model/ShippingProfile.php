<?php

declare(strict_types=1);

namespace Weline\Shipping\Model;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '配送方案 Profile')]
#[Index(name: 'uk_shipping_profile_scope_code', columns: ['scope_type', 'scope_id', 'profile_code'], type: 'UNIQUE')]
#[Index(name: 'idx_shipping_profile_scope', columns: ['scope_type', 'scope_id', 'is_active'])]
class ShippingProfile extends AbstractModel
{
    public const schema_table = 'w_shipping_profiles';
    public const schema_primary_key = 'profile_id';

    public const SCOPE_WEBSITE = 'website';
    public const SCOPE_STORE = 'store';
    public const SCOPE_CHANNEL = 'channel';

    public const SEED_GENERAL = 'SEED_PROFILE_GENERAL';
    public const SEED_HEAVY = 'SEED_PROFILE_HEAVY';
    public const SEED_CODE_PREFIX = 'SEED_PROFILE_';

    #[Col('int', null, nullable: false, primaryKey: true, autoIncrement: true, comment: 'Profile ID')]
    public const schema_fields_ID = 'profile_id';
    #[Col('varchar', 16, nullable: false, default: 'website', comment: '作用范围类型')]
    public const schema_fields_SCOPE_TYPE = 'scope_type';
    #[Col('int', null, nullable: false, default: 0, comment: '作用范围ID')]
    public const schema_fields_SCOPE_ID = 'scope_id';
    #[Col('varchar', 64, nullable: false, comment: 'Profile 代码')]
    public const schema_fields_PROFILE_CODE = 'profile_code';
    #[Col('varchar', 255, nullable: false, comment: 'Profile 名称')]
    public const schema_fields_PROFILE_NAME = 'profile_name';
    #[Col('int', 1, nullable: false, default: 0, comment: '是否 General')]
    public const schema_fields_IS_GENERAL = 'is_general';
    #[Col('int', 1, nullable: false, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['profile_id'];
    public array $_index_sort_keys = ['profile_id', 'scope_type', 'scope_id', 'profile_code'];

    public function _init(): void
    {
        $this->_table = self::schema_table;
        $this->_primary_key = self::schema_fields_ID;
    }

    public function isSeed(): bool
    {
        return str_starts_with(strtoupper(trim((string)$this->getData(self::schema_fields_PROFILE_CODE))), self::SEED_CODE_PREFIX);
    }
}
