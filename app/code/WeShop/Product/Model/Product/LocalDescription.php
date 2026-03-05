<?php

namespace WeShop\Product\Model\Product;

use Weline\I18n\LocalModel;
use WeShop\Product\Model\Product;

class LocalDescription extends LocalModel
{
    public const indexer = 'product_local_description';
    public const schema_fields_ID = Product::schema_fields_ID;
    public const schema_fields_NAME = Product::schema_fields_name;
    public const schema_fields_DESCRIPTION = Product::schema_fields_description;
    public const schema_fields_SHORT_DESCRIPTION = Product::schema_fields_short_description;
    public const schema_fields_META_NAME = Product::schema_fields_meta_name;
    public const schema_fields_META_DESCRIPTION = Product::schema_fields_meta_description;
    public const schema_fields_META_KEYWORDS = Product::schema_fields_meta_keywords;

    /**
     * 获取本地化产品名称
     * @return string
     */
    public function getLocalName(): string
    {
        return (string)$this->getData(self::schema_fields_NAME);
    }

    /**
     * 获取本地化产品描述
     * @return string
     */
    public function getLocalDescription(): string
    {
        return (string)$this->getData(self::schema_fields_DESCRIPTION);
    }

    /**
     * 获取本地化简短描述
     * @return string
     */
    public function getLocalShortDescription(): string
    {
        return (string)$this->getData(self::schema_fields_SHORT_DESCRIPTION);
    }

    /**
     * 获取本地化Meta名称
     * @return string
     */
    public function getLocalMetaName(): string
    {
        return (string)$this->getData(self::schema_fields_META_NAME);
    }

    /**
     * 获取本地化Meta描述
     * @return string
     */
    public function getLocalMetaDescription(): string
    {
        return (string)$this->getData(self::schema_fields_META_DESCRIPTION);
    }

    /**
     * 获取本地化Meta关键词
     * @return string
     */
    public function getLocalMetaKeywords(): string
    {
        return (string)$this->getData(self::schema_fields_META_KEYWORDS);
    }
}