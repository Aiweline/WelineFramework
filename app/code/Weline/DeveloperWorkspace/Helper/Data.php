<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\DeveloperWorkspace\Helper;

use Weline\DeveloperWorkspace\Model\Document;
use Weline\Framework\Manager\ObjectManager;

class Data extends \Weline\Framework\App\Helper
{
    public static function getCatalogModel(): \Weline\DeveloperWorkspace\Model\Document\Catalog
    {
        return ObjectManager::getInstance(\Weline\DeveloperWorkspace\Model\Document\Catalog::class);
    }

    public static function getDocuments()
    {
        /**@var Document $document */
        $document = ObjectManager::getInstance(Document::class);
        return $document->select()->fetch();
    }

    public static function getDocumentsByCategoryId(int $id)
    {
        /**@var Document $document */
        $document = ObjectManager::getInstance(Document::class);
        return $document->loadByCatalogId($id);
    }
}
