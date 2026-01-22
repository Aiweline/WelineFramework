<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * SEO 主体模型
 * 
 * 用于存储需要进行SEO优化的主体（店铺、网站等）
 * 
 * @package Weline_Seo
 */
class SeoSubject extends Model
{
    public const table = 'weline_seo_subject';
    public const fields_ID = 'subject_id';
    public const fields_SUBJECT_TYPE = 'subject_type';
    public const fields_SUBJECT_ID = 'subject_entity_id';
    public const fields_SCOPE = 'scope';
    public const fields_MODULE = 'module';
    public const fields_URL = 'url';
    public const fields_TITLE = 'title';
    public const fields_DESCRIPTION = 'description';
    public const fields_LOCALE = 'locale';
    public const fields_STATUS = 'status';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';
    public const fields_LAST_SYNC_AT = 'last_sync_at';

    // 主体类型常量
    public const SUBJECT_TYPE_STORE = 'store';
    public const SUBJECT_TYPE_WEBSITE = 'website';
    public const SUBJECT_TYPE_PRODUCT = 'product';
    public const SUBJECT_TYPE_PAGE = 'page';

    // 状态常量
    public const STATUS_ENABLED = 1;
    public const STATUS_DISABLED = 0;

    /**
     * 安装数据表
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('SEO主体表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'primary key auto_increment',
                    '主体ID'
                )
                ->addColumn(
                    self::fields_SUBJECT_TYPE,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'not null',
                    '主体类型'
                )
                ->addColumn(
                    self::fields_SUBJECT_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'not null',
                    '主体实体ID'
                )
                ->addColumn(
                    self::fields_SCOPE,
                    TableInterface::column_type_VARCHAR,
                    100,
                    '',
                    '业务scope标识，如page_builder、catalog等'
                )
                ->addColumn(
                    self::fields_MODULE,
                    TableInterface::column_type_VARCHAR,
                    150,
                    '',
                    '来源模块名，例如GuoLaiRen_PageBuilder'
                )
                ->addColumn(
                    self::fields_URL,
                    TableInterface::column_type_VARCHAR,
                    500,
                    '',
                    'URL地址'
                )
                ->addColumn(
                    self::fields_TITLE,
                    TableInterface::column_type_VARCHAR,
                    255,
                    '',
                    '标题'
                )
                ->addColumn(
                    self::fields_DESCRIPTION,
                    TableInterface::column_type_TEXT,
                    0,
                    '',
                    '描述'
                )
                ->addColumn(
                    self::fields_LOCALE,
                    TableInterface::column_type_VARCHAR,
                    10,
                    "default 'zh-CN'",
                    '语言代码'
                )
                ->addColumn(
                    self::fields_STATUS,
                    TableInterface::column_type_INTEGER,
                    1,
                    'default 1',
                    '状态：1启用，0禁用'
                )
                ->addColumn(
                    self::fields_CREATED_AT,
                    TableInterface::column_type_DATETIME,
                    0,
                    '',
                    '创建时间'
                )
                ->addColumn(
                    self::fields_UPDATED_AT,
                    TableInterface::column_type_DATETIME,
                    0,
                    '',
                    '更新时间'
                )
                ->addColumn(
                    self::fields_LAST_SYNC_AT,
                    TableInterface::column_type_DATETIME,
                    0,
                    '',
                    '最后同步时间'
                )
                ->addIndex(
                    TableInterface::index_type_UNIQUE,
                    'idx_subject_unique',
                    [self::fields_SUBJECT_TYPE, self::fields_SUBJECT_ID],
                    '主体唯一索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_subject_type',
                    self::fields_SUBJECT_TYPE,
                    '主体类型索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_status',
                    self::fields_STATUS,
                    '状态索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_scope_module',
                    [self::fields_SCOPE, self::fields_MODULE],
                    'scope+module索引'
                )
                ->create();
        }
    }

    /**
     * 开发模式设置
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * 升级数据表
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            return;
        }

        // 为旧表补充 scope 字段
        if (!$setup->hasField(self::fields_SCOPE)) {
            $setup->alterTable()->addColumn(
                self::fields_SCOPE,
                '',
                TableInterface::column_type_VARCHAR,
                100,
                '',
                '业务scope标识，如page_builder、catalog等'
            )->alter();
        }

        // 为旧表补充 module 字段
        if (!$setup->hasField(self::fields_MODULE)) {
            $setup->alterTable()->addColumn(
                self::fields_MODULE,
                '',
                TableInterface::column_type_VARCHAR,
                150,
                '',
                '来源模块名，例如GuoLaiRen_PageBuilder'
            )->alter();
        }
    }

    /**
     * 保存前处理
     */
    public function save_before(): void
    {
        parent::save_before();
        
        if (!$this->getData(self::fields_CREATED_AT)) {
            $this->setData(self::fields_CREATED_AT, date('Y-m-d H:i:s'));
        }
        $this->setData(self::fields_UPDATED_AT, date('Y-m-d H:i:s'));
    }

    /**
     * 根据主体类型和ID查找或创建主体
     * 
     * @param string $subjectType 主体类型
     * @param int $subjectId 主体ID
     * @return self
     */
    public function findOrCreate(string $subjectType, int $subjectId): self
    {
        $this->reset()
            ->where(self::fields_SUBJECT_TYPE, $subjectType)
            ->where(self::fields_SUBJECT_ID, $subjectId)
            ->find()
            ->fetch();

        if (!$this->getId()) {
            $this->setData(self::fields_SUBJECT_TYPE, $subjectType)
                ->setData(self::fields_SUBJECT_ID, $subjectId)
                ->setData(self::fields_STATUS, self::STATUS_ENABLED);
        }

        return $this;
    }

    // ===== Getters and Setters =====

    public function getSubjectType(): string
    {
        return (string)$this->getData(self::fields_SUBJECT_TYPE);
    }

    public function setSubjectType(string $subjectType): self
    {
        return $this->setData(self::fields_SUBJECT_TYPE, $subjectType);
    }

    public function getSubjectId(): int
    {
        return (int)$this->getData(self::fields_SUBJECT_ID);
    }

    public function setSubjectId(int $subjectId): self
    {
        return $this->setData(self::fields_SUBJECT_ID, $subjectId);
    }

    public function getUrl(): string
    {
        return (string)$this->getData(self::fields_URL);
    }

    public function setUrl(string $url): self
    {
        return $this->setData(self::fields_URL, $url);
    }

    public function getTitle(): string
    {
        return (string)$this->getData(self::fields_TITLE);
    }

    public function setTitle(string $title): self
    {
        return $this->setData(self::fields_TITLE, $title);
    }

    public function getDescription(): string
    {
        return (string)$this->getData(self::fields_DESCRIPTION);
    }

    public function setDescription(string $description): self
    {
        return $this->setData(self::fields_DESCRIPTION, $description);
    }

    public function getLocale(): string
    {
        return (string)$this->getData(self::fields_LOCALE);
    }

    public function setLocale(string $locale): self
    {
        return $this->setData(self::fields_LOCALE, $locale);
    }

    public function getStatus(): int
    {
        return (int)$this->getData(self::fields_STATUS);
    }

    public function setStatus(int $status): self
    {
        return $this->setData(self::fields_STATUS, $status);
    }

    public function isEnabled(): bool
    {
        return $this->getStatus() === self::STATUS_ENABLED;
    }
}

