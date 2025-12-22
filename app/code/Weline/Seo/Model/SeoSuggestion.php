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
 * SEO AI建议模型
 * 
 * @package Weline_Seo
 */
class SeoSuggestion extends Model
{
    public const table = 'weline_seo_suggestion';
    public const fields_ID = 'suggestion_id';
    public const fields_SUBJECT_ID = 'subject_id';
    public const fields_CONTENT = 'content';
    public const fields_KEYWORDS = 'keywords';
    public const fields_PRIORITY = 'priority';
    public const fields_STATUS = 'status';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    // 状态常量
    public const STATUS_ACTIVE = 1;
    public const STATUS_ARCHIVED = 0;

    /**
     * 安装数据表
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('SEO AI建议表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'primary key auto_increment',
                    '建议ID'
                )
                ->addColumn(
                    self::fields_SUBJECT_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'not null',
                    '主体ID'
                )
                ->addColumn(
                    self::fields_CONTENT,
                    TableInterface::column_type_TEXT,
                    0,
                    '',
                    '建议内容（JSON格式存储结构化建议）'
                )
                ->addColumn(
                    self::fields_KEYWORDS,
                    TableInterface::column_type_TEXT,
                    0,
                    '',
                    '推荐关键词（JSON数组）'
                )
                ->addColumn(
                    self::fields_PRIORITY,
                    TableInterface::column_type_INTEGER,
                    1,
                    'default 0',
                    '优先级'
                )
                ->addColumn(
                    self::fields_STATUS,
                    TableInterface::column_type_INTEGER,
                    1,
                    'default 1',
                    '状态：1活跃，0已归档'
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
                    'idx_status',
                    self::fields_STATUS,
                    '状态索引'
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

    /**
     * 获取关键词数组
     * 
     * @return array
     */
    public function getKeywordsArray(): array
    {
        $keywords = $this->getData(self::fields_KEYWORDS);
        if (empty($keywords)) {
            return [];
        }
        
        if (is_string($keywords)) {
            $decoded = json_decode($keywords, true);
            return is_array($decoded) ? $decoded : [];
        }
        
        return is_array($keywords) ? $keywords : [];
    }

    /**
     * 设置关键词数组
     * 
     * @param array $keywords
     * @return self
     */
    public function setKeywordsArray(array $keywords): self
    {
        return $this->setData(self::fields_KEYWORDS, json_encode($keywords, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 获取建议内容数组
     * 
     * @return array
     */
    public function getContentArray(): array
    {
        $content = $this->getData(self::fields_CONTENT);
        if (empty($content)) {
            return [];
        }
        
        if (is_string($content)) {
            $decoded = json_decode($content, true);
            return is_array($decoded) ? $decoded : [];
        }
        
        return is_array($content) ? $content : [];
    }

    /**
     * 设置建议内容数组
     * 
     * @param array $content
     * @return self
     */
    public function setContentArray(array $content): self
    {
        return $this->setData(self::fields_CONTENT, json_encode($content, JSON_UNESCAPED_UNICODE));
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

    public function getContent(): string
    {
        return (string)$this->getData(self::fields_CONTENT);
    }

    public function setContent(string $content): self
    {
        return $this->setData(self::fields_CONTENT, $content);
    }

    public function getKeywords(): string
    {
        return (string)$this->getData(self::fields_KEYWORDS);
    }

    public function setKeywords(string $keywords): self
    {
        return $this->setData(self::fields_KEYWORDS, $keywords);
    }

    public function getPriority(): int
    {
        return (int)$this->getData(self::fields_PRIORITY);
    }

    public function setPriority(int $priority): self
    {
        return $this->setData(self::fields_PRIORITY, $priority);
    }

    public function getStatus(): int
    {
        return (int)$this->getData(self::fields_STATUS);
    }

    public function setStatus(int $status): self
    {
        return $this->setData(self::fields_STATUS, $status);
    }
}

