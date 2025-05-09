<?php

namespace WeShop\Store\Model\Store;

use WeShop\Store\Model\Store;

class LocalDescription extends \Weline\I18n\LocalModel
{
    public const indexer = 'store_local_description';
    public const fields_ID = 'store_id';
    public const fields_NAME = Store::fields_NAME;
    public const fields_DESCRIPTION = Store::fields_DESCRIPTION;
}
