<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\DeveloperWorkspace\Model;

use Weline\DeveloperWorkspace\Model\Document\Catalog;
use Weline\Framework\Manager\ObjectManager;

class ModelService
{
    public static function getDocumentModel(): Document
    {
        return ObjectManager::getInstance(Document::class);
    }

    public static function getDocumentCatalogModel(): Catalog
    {
        return ObjectManager::getInstance(Catalog::class);
    }
}
