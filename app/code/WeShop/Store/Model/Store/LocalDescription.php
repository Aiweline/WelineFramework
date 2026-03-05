<?php

namespace WeShop\Store\Model\Store;

use Weline\Framework\Database\Schema\Attribute\Col;

class LocalDescription extends \Weline\I18n\LocalModel
{
    #[Col('varchar', 20, nullable: false, comment: '语言代码')]
    public const schema_fields_LOCALE_CODE = 'locale_code';
    public const schema_primary_keys = ['store_id', 'locale_code'];

    public const indexer = 'store_local_description';
    public const schema_fields_ID = 'store_id';
    public const schema_fields_NAME = \WeShop\Store\Model\Store::schema_fields_NAME;
    public const schema_fields_DESCRIPTION = \WeShop\Store\Model\Store::schema_fields_DESCRIPTION;
}
