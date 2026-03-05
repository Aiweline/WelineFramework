<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/** SEO AI建议模型 */
#[Table(comment: 'SEO AI建议表')]
#[Index(name: 'idx_subject_id', columns: ['subject_id'])]
#[Index(name: 'idx_status', columns: ['status'])]
class SeoSuggestion extends Model
{
    public const schema_table = 'weline_seo_suggestion';
    public const schema_primary_key = 'suggestion_id';
    #[Col('int', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: '建议ID')]
    public const schema_fields_ID = 'suggestion_id';
    #[Col('int', 0, nullable: false, comment: '主体ID')]
    public const schema_fields_SUBJECT_ID = 'subject_id';
    #[Col('text', comment: '建议内容JSON')]
    public const schema_fields_CONTENT = 'content';
    #[Col('text', comment: '推荐关键词JSON')]
    public const schema_fields_KEYWORDS = 'keywords';
    #[Col('int', 1, nullable: false, default: 0, comment: '优先级')]
    public const schema_fields_PRIORITY = 'priority';
    #[Col('int', 1, nullable: false, default: 1, comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    // 状态常量
    public const STATUS_ACTIVE = 1;
    public const STATUS_ARCHIVED = 0;
/**
     * 保存前处理
     */
    public function save_before(): void
    {
        parent::save_before();
        
        if (!$this->getData(self::schema_fields_CREATED_AT)) {
            $this->setData(self::schema_fields_CREATED_AT, date('Y-m-d H:i:s'));
        }
        $this->setData(self::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'));
    }

    /**
     * 获取关键词数组
     * 
     * @return array
     */
    public function getKeywordsArray(): array
    {
        $keywords = $this->getData(self::schema_fields_KEYWORDS);
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
        return $this->setData(self::schema_fields_KEYWORDS, json_encode($keywords, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 获取建议内容数组
     * 
     * @return array
     */
    public function getContentArray(): array
    {
        $content = $this->getData(self::schema_fields_CONTENT);
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
        return $this->setData(self::schema_fields_CONTENT, json_encode($content, JSON_UNESCAPED_UNICODE));
    }

    // ===== Getters and Setters =====

    public function getSubjectId(): int
    {
        return (int)$this->getData(self::schema_fields_SUBJECT_ID);
    }

    public function setSubjectId(int $subjectId): self
    {
        return $this->setData(self::schema_fields_SUBJECT_ID, $subjectId);
    }

    public function getContent(): string
    {
        return (string)$this->getData(self::schema_fields_CONTENT);
    }

    public function setContent(string $content): self
    {
        return $this->setData(self::schema_fields_CONTENT, $content);
    }

    public function getKeywords(): string
    {
        return (string)$this->getData(self::schema_fields_KEYWORDS);
    }

    public function setKeywords(string $keywords): self
    {
        return $this->setData(self::schema_fields_KEYWORDS, $keywords);
    }

    public function getPriority(): int
    {
        return (int)$this->getData(self::schema_fields_PRIORITY);
    }

    public function setPriority(int $priority): self
    {
        return $this->setData(self::schema_fields_PRIORITY, $priority);
    }

    public function getStatus(): int
    {
        return (int)$this->getData(self::schema_fields_STATUS);
    }

    public function setStatus(int $status): self
    {
        return $this->setData(self::schema_fields_STATUS, $status);
    }
}

