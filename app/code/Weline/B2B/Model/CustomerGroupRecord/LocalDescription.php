<?php

declare(strict_types=1);

namespace Weline\B2B\Model\CustomerGroupRecord;

use Weline\B2B\Model\CustomerGroupRecord;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\I18n\Api\Localization\LocalModel;

/**
 * 客户组名称 / 等级说明多语言。主表存源语回退；本表按 local_code 存译文。
 */
#[Table(comment: 'B2B customer group local descriptions')]
#[Index(name: 'uk_b2b_customer_group_local', columns: ['group_row_id', 'local_code'], type: 'UNIQUE')]
class LocalDescription extends LocalModel
{
    public const schema_table = 'weline_b2b_customer_group_local';
    public const schema_primary_key = CustomerGroupRecord::schema_fields_ID;
    public const indexer = 'b2b_customer_group_local';

    #[Col(type: 'bigint', length: 20, nullable: false, primaryKey: true, comment: 'Group row ID')]
    public const schema_fields_ID = CustomerGroupRecord::schema_fields_ID;

    #[Col(type: 'varchar', length: 20, nullable: false, primaryKey: true, comment: 'Locale code')]
    public const schema_fields_LOCALE_CODE = self::schema_fields_local_code;

    #[Col(type: 'varchar', length: 128, nullable: true, comment: 'Localized display name')]
    public const schema_fields_NAME = CustomerGroupRecord::schema_fields_NAME;

    #[Col(type: 'text', nullable: true, comment: 'Localized level description')]
    public const schema_fields_DESCRIPTION = CustomerGroupRecord::schema_fields_DESCRIPTION;

    /** TraitLocalModel::getName() 读此列 */
    public const schema_fields_name = CustomerGroupRecord::schema_fields_NAME;
}
