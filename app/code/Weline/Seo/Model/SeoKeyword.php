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
/** SEO 关键词模型 */
#[Table(comment: 'SEO关键词表')]
#[Index(name: 'idx_subject_id', columns: ['subject_id'])]
#[Index(name: 'idx_keyword', columns: ['keyword'])]
#[Index(name: 'idx_status', columns: ['status'])]
#[Index(name: 'idx_subject_keyword', columns: ['subject_id', 'keyword'], type: 'UNIQUE')]
class SeoKeyword extends Model
{

    public const schema_table = 'weline_seo_keyword';
    public const schema_primary_key = 'keyword_id';
    #[Col('int', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: '关键词ID')]
    public const schema_fields_ID = 'keyword_id';
    #[Col('int', 0, nullable: false, comment: '主体ID')]
    public const schema_fields_SUBJECT_ID = 'subject_id';
    #[Col('varchar', 255, nullable: false, comment: '关键词')]
    public const schema_fields_KEYWORD = 'keyword';
    #[Col('int', 1, nullable: false, default: 0, comment: '优先级')]
    public const schema_fields_PRIORITY = 'priority';
    #[Col('varchar', 50, nullable: false, default: 'manual', comment: '来源')]
    public const schema_fields_SOURCE = 'source';
    #[Col('int', 1, nullable: false, default: 1, comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    // 关键词来源常量
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_AI = 'ai';
    public const SOURCE_EXTRACTED = 'extracted';
    public const SOURCE_TREND = 'trend';

    // 状态常量
    public const STATUS_ENABLED = 1;
    public const STATUS_DISABLED = 0;

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

    // ===== Getters and Setters =====

    public function getSubjectId(): int
    {
        return (int)$this->getData(self::schema_fields_SUBJECT_ID);
    }

    public function setSubjectId(int $subjectId): self
    {
        return $this->setData(self::schema_fields_SUBJECT_ID, $subjectId);
    }

    public function getKeyword(): string
    {
        return (string)$this->getData(self::schema_fields_KEYWORD);
    }

    public function setKeyword(string $keyword): self
    {
        return $this->setData(self::schema_fields_KEYWORD, $keyword);
    }

    public function getPriority(): int
    {
        return (int)$this->getData(self::schema_fields_PRIORITY);
    }

    public function setPriority(int $priority): self
    {
        return $this->setData(self::schema_fields_PRIORITY, $priority);
    }

    public function getSource(): string
    {
        return (string)$this->getData(self::schema_fields_SOURCE);
    }

    public function setSource(string $source): self
    {
        return $this->setData(self::schema_fields_SOURCE, $source);
    }

    public function getStatus(): int
    {
        return (int)$this->getData(self::schema_fields_STATUS);
    }

    public function setStatus(int $status): self
    {
        return $this->setData(self::schema_fields_STATUS, $status);
    }

    public function isEnabled(): bool
    {
        return $this->getStatus() === self::STATUS_ENABLED;
    }
}


