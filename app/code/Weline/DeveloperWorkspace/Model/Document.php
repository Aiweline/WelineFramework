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
use Weline\Framework\Database\Exception\DbException;
use Weline\Framework\Database\Helper\Importer\SqlFile;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Document extends \Weline\Framework\Database\Model
{
    public string $table = 'developer_workspace_document';
    public const fields_ID = 'id';
    public const fields_TITLE = 'title';
    public const fields_summary = 'summary';
    public const fields_AUTHOR_ID = 'author_id';
    public const fields_CATEGORY_ID = 'category_id';
//    public const fields_TAG_ID      = 'tag_id';
    public const fields_CONTEND = 'content';
    public const fields_MODULE_NAME = 'module_name';
    public const fields_FILE_PATH = 'file_path';
    public const fields_FILE_NAME = 'file_name';
    public const fields_IS_AUTO_IMPORTED = 'is_auto_imported';
    public const fields_SORT_ORDER = 'sort_order';

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 升级字段长度
        if ($setup->tableExist()) {
            $setup->getPrinting()->setup('升级数据表字段长度...', $setup->getTable());
            
            // 使用 alterTable() 方法返回 Alter 对象
            $alter = $setup->alterTable('开发文章');
            
            // 修改 title 字段长度为 500
            $alter->alterColumn(self::fields_TITLE, self::fields_TITLE, '', TableInterface::column_type_VARCHAR, 500, 'not null', '标题');
            
            // 修改 summary 字段长度为 1000
            $alter->alterColumn(self::fields_summary, self::fields_summary, '', TableInterface::column_type_VARCHAR, 1000, 'not null', '摘要');
            
            // 修改 content 字段为 LONGTEXT 类型（LONGTEXT 不需要长度参数）
            $alter->alterColumn(self::fields_CONTEND, self::fields_CONTEND, '', TableInterface::column_type_LONG_TEXT, 0, 'not null', '内容');
            
            // 添加新字段
            if (!$setup->hasField(self::fields_MODULE_NAME)) {
                $alter->addColumn(self::fields_MODULE_NAME, '', TableInterface::column_type_VARCHAR, 100, '', '所属模块');
            }
            
            if (!$setup->hasField(self::fields_FILE_PATH)) {
                $alter->addColumn(self::fields_FILE_PATH, '', TableInterface::column_type_VARCHAR, 500, '', '文件路径');
            }
            
            if (!$setup->hasField(self::fields_FILE_NAME)) {
                $alter->addColumn(self::fields_FILE_NAME, '', TableInterface::column_type_VARCHAR, 200, '', '文件名');
            }
            
            if (!$setup->hasField(self::fields_IS_AUTO_IMPORTED)) {
                $alter->addColumn(self::fields_IS_AUTO_IMPORTED, '', TableInterface::column_type_INTEGER, 1, 'default 0', '是否自动导入');
            }
            
            if (!$setup->hasField(self::fields_SORT_ORDER)) {
                $alter->addColumn(self::fields_SORT_ORDER, '', TableInterface::column_type_INTEGER, 11, 'default 0', '排序');
            }
            
            // 添加唯一索引：module_name + file_path，防止重复导入
            // 注意：如果索引已存在，addIndex 会忽略或报错（取决于数据库）
            // 这里使用 try-catch 来忽略已存在的索引
            try {
                $alter->addIndex('UNIQUE', 'idx_module_file_unique', [self::fields_MODULE_NAME, self::fields_FILE_PATH], '模块文件唯一索引');
            } catch (\Exception $e) {
                // 索引可能已存在，忽略错误
                $setup->getPrinting()->warning('索引可能已存在: ' . $e->getMessage());
            }
            
            // 执行修改
            $alter->alter();
        }
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
                ->addColumn(self::fields_TITLE, TableInterface::column_type_VARCHAR, 500, 'not null', '标题')
                ->addColumn(self::fields_summary, TableInterface::column_type_VARCHAR, 1000, 'not null', '摘要')
                ->addColumn(self::fields_AUTHOR_ID, TableInterface::column_type_INTEGER, 0, 'default 0', '作者ID')
                ->addColumn(self::fields_CONTEND, TableInterface::column_type_LONG_TEXT, 0, 'not null', '内容')
                ->addColumn(self::fields_MODULE_NAME, TableInterface::column_type_VARCHAR, 100, '', '所属模块')
                ->addColumn(self::fields_FILE_PATH, TableInterface::column_type_VARCHAR, 500, '', '文件路径')
                ->addColumn(self::fields_FILE_NAME, TableInterface::column_type_VARCHAR, 200, '', '文件名')
                ->addColumn(self::fields_IS_AUTO_IMPORTED, TableInterface::column_type_INTEGER, 1, 'default 0', '是否自动导入')
                ->addColumn(self::fields_SORT_ORDER, TableInterface::column_type_INTEGER, 0, 'default 0', '排序')
                // 添加唯一索引：module_name + file_path，防止重复导入
                ->addIndex('UNIQUE', 'idx_module_file_unique', [self::fields_MODULE_NAME, self::fields_FILE_PATH], '模块文件唯一索引')
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
        // 返回前端文档浏览页面的URL
        return $url->getUrl('/dev/tool/', ['id' => $this->getId()]);
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
    
    public function getModuleName(): string
    {
        return $this->getData(self::fields_MODULE_NAME) ?? '';
    }
    
    public function setModuleName(string $moduleName): static
    {
        return $this->setData(self::fields_MODULE_NAME, $moduleName);
    }
    
    public function getFilePath(): string
    {
        return $this->getData(self::fields_FILE_PATH) ?? '';
    }
    
    public function setFilePath(string $filePath): static
    {
        return $this->setData(self::fields_FILE_PATH, $filePath);
    }
    
    public function getFileName(): string
    {
        return $this->getData(self::fields_FILE_NAME) ?? '';
    }
    
    public function setFileName(string $fileName): static
    {
        return $this->setData(self::fields_FILE_NAME, $fileName);
    }
    
    public function isAutoImported(): bool
    {
        return (bool)$this->getData(self::fields_IS_AUTO_IMPORTED);
    }
    
    public function setIsAutoImported(bool $isAutoImported): static
    {
        return $this->setData(self::fields_IS_AUTO_IMPORTED, $isAutoImported ? 1 : 0);
    }
    
    public function getSortOrder(): int
    {
        return (int)($this->getData(self::fields_SORT_ORDER) ?? 0);
    }
    
    public function setSortOrder(int $sortOrder): static
    {
        return $this->setData(self::fields_SORT_ORDER, $sortOrder);
    }
}
