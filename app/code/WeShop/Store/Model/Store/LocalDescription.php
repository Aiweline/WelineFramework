<?php

namespace WeShop\Store\Model\Store;

class LocalDescription extends \Weline\I18n\LocalModel
{
    public const indexer = 'store_local_description';
    public const fields_ID = 'store_id';
    public const fields_NAME = \WeShop\Store\Model\Store::fields_NAME;
    public const fields_DESCRIPTION = \WeShop\Store\Model\Store::fields_DESCRIPTION;
}
