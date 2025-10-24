<?php

namespace WeShop\Product\Model\Product;

use Weline\I18n\LocalModel;
use WeShop\Product\Model\Product;

class LocalDescription extends LocalModel
{
    public const indexer = 'product_local_description';
    public const fields_ID = Product::fields_ID;
    public const fields_NAME = Product::fields_name;
    public const fields_DESCRIPTION = Product::fields_description;
    public const fields_SHORT_DESCRIPTION = Product::fields_short_description;
    public const fields_META_DESCRIPTION = Product::fields_meta_description;
    public const fields_META_KEYWORDS = Product::fields_meta_keywords;
}