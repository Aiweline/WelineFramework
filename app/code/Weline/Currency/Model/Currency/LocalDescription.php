<?php

declare(strict_types=1);

namespace Weline\Currency\Model\Currency;

use Weline\Currency\Model\Currency;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\I18n\Api\Localization\LocalModel;

#[Table(comment: '货币多语言描述表')]
#[Index(name: 'idx_currency_local_description_unique', columns: ['currency_id', 'local_code'], type: 'UNIQUE')]
class LocalDescription extends LocalModel
{
    public const schema_table = 'weline_currency_local_description';
    public const schema_primary_key = self::schema_fields_LOCAL_DESCRIPTION_ID;
    public const indexer = 'currency_local_description';

    #[Col(type: 'int', nullable: false, primaryKey: true, autoIncrement: true, comment: '本地化描述ID')]
    public const schema_fields_LOCAL_DESCRIPTION_ID = 'local_description_id';

    #[Col(type: 'int', nullable: false, comment: '货币ID')]
    public const schema_fields_ID = Currency::schema_fields_ID;

    #[Col(type: 'varchar', length: 255, nullable: true, comment: '货币名称')]
    public const schema_fields_name = Currency::schema_fields_NAME;

    #[Col(type: 'text', nullable: true, comment: '本地化扩展配置')]
    public const schema_fields_config = 'config';
}
