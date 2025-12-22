<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归WeShop所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/7/16 15:30:37
 */

namespace WeShop\Product\Model\Category;

use WeShop\Product\Model\Category;

class LocalDescription extends \Weline\I18n\LocalModel
{
    public const indexer = 'product_category_local_description';
    public const fields_ID = 'category_id';
    public const fields_name = 'name';
    public const fields_DESCRIPTION = 'description';
    public const fields_META_TITLE = 'meta_title';
    public const fields_META_DESCRIPTION = 'meta_description';
    public const fields_META_KEYWORDS = 'meta_keywords';

    /**
     * 获取本地化分类名称
     * @return string
     */
    public function getLocalName(): string
    {
        return (string)$this->getData(self::fields_name);
    }

    /**
     * 获取本地化分类描述
     * @return string
     */
    public function getLocalDescription(): string
    {
        return (string)$this->getData(self::fields_DESCRIPTION);
    }

    /**
     * 获取本地化Meta标题
     * @return string
     */
    public function getLocalMetaTitle(): string
    {
        return (string)$this->getData(self::fields_META_TITLE);
    }

    /**
     * 获取本地化Meta描述
     * @return string
     */
    public function getLocalMetaDescription(): string
    {
        return (string)$this->getData(self::fields_META_DESCRIPTION);
    }

    /**
     * 获取本地化Meta关键词
     * @return string
     */
    public function getLocalMetaKeywords(): string
    {
        return (string)$this->getData(self::fields_META_KEYWORDS);
    }
}
