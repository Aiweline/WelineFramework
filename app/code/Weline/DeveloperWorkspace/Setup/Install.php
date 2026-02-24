<?php

declare(strict_types=1);
/**
 * 文件信息
 * 作者：邹万才
 * 网名：秋风雁飞(Aiweline)
 * 网站：www.aiweline.com/bbs.aiweline.com
 * 工具：PhpStorm
 * 日期：2021/5/22
 * 时间：11:06
 * 描述：此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
 *
 * 禁止直接使用方言：表结构由 ModelSetup 抽象 API 创建（跨 MySQL/PostgreSQL），
 * 种子数据由 InstallData 通过 Model 插入，不使用 raw SQL 文件。
 */

namespace Weline\DeveloperWorkspace\Setup;

use Weline\DeveloperWorkspace\Model\Document;
use Weline\DeveloperWorkspace\Model\Document\Catalog;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data;
use Weline\Framework\Setup\Db\ModelSetup;

class Install implements \Weline\Framework\Setup\InstallInterface
{
    public function setup(Data\Setup $setup, Data\Context $context): void
    {
        $modelSetup = ObjectManager::make(ModelSetup::class);

        // 1. 先安装目录表（依赖 ModelSetup 抽象 API，由适配器生成跨库 DDL）
        /** @var Catalog $catalog */
        $catalog = ObjectManager::getInstance(Catalog::class);
        $modelSetup->putModel($catalog);
        $catalog->install($modelSetup, $context);

        // 2. 安装文档表
        /** @var Document $document */
        $document = ObjectManager::getInstance(Document::class);
        $modelSetup->putModel($document);
        $document->install($modelSetup, $context);

        // 3. 安装种子数据（通过 Model 插入，跨数据库兼容）
        /** @var InstallData $installData */
        $installData = ObjectManager::getInstance(InstallData::class);
        $installData->install();
    }
}
