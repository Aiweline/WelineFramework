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
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * SEO 关键词模型
 * 
 * @package Weline_Seo
 */
class SeoKeyword extends Model
{
    public const table = 'weline_seo_keyword';
    public const fields_ID = 'keyword_id';
    public const fields_SUBJECT_ID = 'subject_id';
    public const fields_KEYWORD = 'keyword';
    public const fields_PRIORITY = 'priority';
    public const fields_SOURCE = 'source';
    public const fields_STATUS = 'status';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    // 关键词来源常量
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_AI = 'ai';
    public const SOURCE_EXTRACTED = 'extracted';
    public const SOURCE_TREND = 'trend';

    // 状态常量
    public const STATUS_ENABLED = 1;
    public const STATUS_DISABLED = 0;

    /**
     * 安装数据表
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('SEO关键词表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'primary key auto_increment',
                    '关键词ID'
                )
                ->addColumn(
                    self::fields_SUBJECT_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'not null',
                    '主体ID'
                )
                ->addColumn(
                    self::fields_KEYWORD,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null',
                    '关键词'
                )
                ->addColumn(
                    self::fields_PRIORITY,
                    TableInterface::column_type_INTEGER,
                    1,
                    'default 0',
                    '优先级：数字越大优先级越高'
                )
                ->addColumn(
                    self::fields_SOURCE,
                    TableInterface::column_type_VARCHAR,
                    50,
                    "default 'manual'",
                    '来源：manual手动，ai AI生成，extracted提取，trend趋势'
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
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_subject_id',
                    self::fields_SUBJECT_ID,
                    '主体ID索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_keyword',
                    self::fields_KEYWORD,
                    '关键词索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_status',
                    self::fields_STATUS,
                    '状态索引'
                )
                ->addIndex(
                    TableInterface::index_type_UNIQUE,
                    'idx_subject_keyword',
                    [self::fields_SUBJECT_ID, self::fields_KEYWORD],
                    '主体关键词唯一索引'
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
        // 升级逻辑
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

    // ===== Getters and Setters =====

    public function getSubjectId(): int
    {
        return (int)$this->getData(self::fields_SUBJECT_ID);
    }

    public function setSubjectId(int $subjectId): self
    {
        return $this->setData(self::fields_SUBJECT_ID, $subjectId);
    }

    public function getKeyword(): string
    {
        return (string)$this->getData(self::fields_KEYWORD);
    }

    public function setKeyword(string $keyword): self
    {
        return $this->setData(self::fields_KEYWORD, $keyword);
    }

    public function getPriority(): int
    {
        return (int)$this->getData(self::fields_PRIORITY);
    }

    public function setPriority(int $priority): self
    {
        return $this->setData(self::fields_PRIORITY, $priority);
    }

    public function getSource(): string
    {
        return (string)$this->getData(self::fields_SOURCE);
    }

    public function setSource(string $source): self
    {
        return $this->setData(self::fields_SOURCE, $source);
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

