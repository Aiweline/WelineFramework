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

class LocalDescription extends \Weline\I18n\LocalModel
{
    public const indexer = 'product_category_local_description';
    public const fields_ID = 'category_id';
    public const fields_name = 'name';
}
