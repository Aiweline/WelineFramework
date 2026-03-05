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
use Weline\Framework\Manager\ObjectManager;
/** SEO 主体模型 - 存储需SEO优化的主体（店铺、网站等） */
#[Table(comment: 'SEO主体表')]
#[Index(name: 'idx_subject_unique', columns: ['subject_type', 'subject_entity_id'], type: 'UNIQUE')]
#[Index(name: 'idx_subject_type', columns: ['subject_type'])]
#[Index(name: 'idx_status', columns: ['status'])]
#[Index(name: 'idx_scope_module', columns: ['scope', 'module'])]
class SeoSubject extends Model
{

    public const schema_table = 'weline_seo_subject';
    public const schema_primary_key = 'subject_id';
    #[Col('int', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: '主体ID')]
    public const schema_fields_ID = 'subject_id';
    #[Col('varchar', 50, nullable: false, comment: '主体类型')]
    public const schema_fields_SUBJECT_TYPE = 'subject_type';
    #[Col('int', 0, nullable: false, comment: '主体实体ID')]
    public const schema_fields_SUBJECT_ID = 'subject_entity_id';
    #[Col('varchar', 100, comment: '业务scope')]
    public const schema_fields_SCOPE = 'scope';
    #[Col('varchar', 150, comment: '来源模块')]
    public const schema_fields_MODULE = 'module';
    #[Col('varchar', 500, comment: 'URL地址')]
    public const schema_fields_URL = 'url';
    #[Col('varchar', 255, comment: '标题')]
    public const schema_fields_TITLE = 'title';
    #[Col('text', comment: '描述')]
    public const schema_fields_DESCRIPTION = 'description';
    #[Col('varchar', 10, nullable: false, default: 'zh-CN', comment: '语言代码')]
    public const schema_fields_LOCALE = 'locale';
    #[Col('int', 1, nullable: false, default: 1, comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    #[Col('datetime', comment: '最后同步时间')]
    public const schema_fields_LAST_SYNC_AT = 'last_sync_at';

    // 主体类型常量
    public const SUBJECT_TYPE_STORE = 'store';
    public const SUBJECT_TYPE_WEBSITE = 'website';
    public const SUBJECT_TYPE_PRODUCT = 'product';
    public const SUBJECT_TYPE_PAGE = 'page';

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
            ->where(self::schema_fields_SUBJECT_TYPE, $subjectType)
            ->where(self::schema_fields_SUBJECT_ID, $subjectId)
            ->find()
            ->fetch();

        if (!$this->getId()) {
            $this->setData(self::schema_fields_SUBJECT_TYPE, $subjectType)
                ->setData(self::schema_fields_SUBJECT_ID, $subjectId)
                ->setData(self::schema_fields_STATUS, self::STATUS_ENABLED);
        }

        return $this;
    }

    // ===== Getters and Setters =====

    public function getSubjectType(): string
    {
        return (string)$this->getData(self::schema_fields_SUBJECT_TYPE);
    }

    public function setSubjectType(string $subjectType): self
    {
        return $this->setData(self::schema_fields_SUBJECT_TYPE, $subjectType);
    }

    public function getSubjectId(): int
    {
        return (int)$this->getData(self::schema_fields_SUBJECT_ID);
    }

    public function setSubjectId(int $subjectId): self
    {
        return $this->setData(self::schema_fields_SUBJECT_ID, $subjectId);
    }

    public function getUrl(): string
    {
        return (string)$this->getData(self::schema_fields_URL);
    }

    public function setUrl(string $url): self
    {
        return $this->setData(self::schema_fields_URL, $url);
    }

    public function getTitle(): string
    {
        return (string)$this->getData(self::schema_fields_TITLE);
    }

    public function setTitle(string $title): self
    {
        return $this->setData(self::schema_fields_TITLE, $title);
    }

    public function getDescription(): string
    {
        return (string)$this->getData(self::schema_fields_DESCRIPTION);
    }

    public function setDescription(string $description): self
    {
        return $this->setData(self::schema_fields_DESCRIPTION, $description);
    }

    public function getLocale(): string
    {
        return (string)$this->getData(self::schema_fields_LOCALE);
    }

    public function setLocale(string $locale): self
    {
        return $this->setData(self::schema_fields_LOCALE, $locale);
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


