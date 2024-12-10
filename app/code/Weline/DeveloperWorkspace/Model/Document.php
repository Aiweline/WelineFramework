<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\DeveloperWorkspace\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Document extends \Weline\Framework\Database\Model
{
    public const fields_ID          = 'id';
    public const fields_TITLE       = 'title';
    public const fields_summary     = 'summary';
    public const fields_AUTHOR_ID   = 'author_id';
    public const fields_CATEGORY_ID = 'category_id';
//    public const fields_TAG_ID      = 'tag_id';
    public const fields_CONTEND = 'content';

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
//        $setup->dropTable();
        $this->install($setup, $context);
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->getPrinting()->setup('安装数据表...', $setup->getTable());
            $setup->createTable('开发文章')
                  ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'primary key auto_increment ', 'ID')
                  ->addColumn(self::fields_CATEGORY_ID, TableInterface::column_type_INTEGER, 0, 'not null ', '分类ID')
                  ->addColumn(self::fields_TITLE, TableInterface::column_type_VARCHAR, 120, 'not null', '标题')
                  ->addColumn(self::fields_summary, TableInterface::column_type_VARCHAR, 250, 'not null', '摘要')
                  ->addColumn(self::fields_AUTHOR_ID, TableInterface::column_type_INTEGER, 0, 'default 0', '作者ID')
                  ->addColumn(self::fields_CONTEND, TableInterface::column_type_TEXT, 0, 'not null', '内容')
                  ->create();
        }
    }

    public function getTitle()
    {
        return $this->getData(self::fields_TITLE);
    }

    public function setTitle(string $title): Document
    {
        return $this->setData(self::fields_TITLE, $title);
    }

    public function getAuthorId()
    {
        return $this->getData(self::fields_AUTHOR_ID);
    }

    public function setAuthorID(string|int $author_id): Document
    {
        return $this->setData(self::fields_AUTHOR_ID, $author_id);
    }

//    public function getTagId()
//    {
//        return $this->getData(self::fields_TAG_ID);
//    }
//
//    public function setTagID(string|int $tag_id): Document
//    {
//        return $this->setData(self::fields_TAG_ID, $tag_id);
//    }

    public function getContent()
    {
        return $this->getData(self::fields_CONTEND);
    }

    public function getDecodeContent()
    {
        return htmlspecialchars_decode($this->getContent());
    }

    public function setContent(string $content): Document
    {
        return $this->setData(self::fields_CONTEND, $content);
    }

    public function setCategoryId(string $category_id): Document
    {
        return $this->setData(self::fields_CATEGORY_ID, $category_id);
    }

    public function getCategoryId()
    {
        return $this->getData(self::fields_CATEGORY_ID);
    }

    public function getUrl()
    {
        /**@var Url $url */
        $url = ObjectManager::getInstance(Url::class);
        return $url->getUrl('/dev/tool/document', ['id' => $this->getId()]);
    }

    /**
     * @DESC          # 方法描述
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/4/19 22:36
     * 参数区：
     *
     * @param int $id
     *
     * @return Document[]
     */
    public function loadByCatalogId(int $id): array
    {
        return $this->where(self::fields_CATEGORY_ID, $id)->select()->fetch()->getItems();
    }
}
