<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫科技 编写，所有解释权归 weline 所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\CustomerService\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '客户语言配置表')]
#[Index(name: 'idx_customer_id', columns: ['customer_id'])]
#[Index(name: 'idx_session_id', columns: ['session_id'])]
#[Index(name: 'idx_email', columns: ['email'])]
class CustomerLanguage extends Model
{

    public const schema_table = 'customer_language';
    public const schema_primary_key = 'language_id';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '语言配置ID')]
    public const schema_fields_ID = 'language_id';
    #[Col('int', comment: '客户ID')]
    public const schema_fields_customer_id = 'customer_id';
    #[Col('varchar', 255, comment: '会话ID')]
    public const schema_fields_session_id = 'session_id';
    #[Col('varchar', 255, comment: '邮箱')]
    public const schema_fields_email = 'email';
    #[Col('varchar', 20, nullable: false, default: 'zh_Hans_CN', comment: '目标语言代码')]
    public const schema_fields_target_locale = 'target_locale';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_created_at = 'created_at';
    #[Col('datetime', comment: '更新时间')]
    public const schema_fields_updated_at = 'updated_at';

    public function _init(): void
    {
        $this->_primary_key = self::schema_fields_ID;
        $this->_table = self::schema_table;
    }

    public function getCustomerId(): ?int
    {
        $id = $this->getData(self::schema_fields_customer_id);
        return $id ? (int)$id : null;
    }

    public function setCustomerId(?int $customerId): static
    {
        return $this->setData(self::schema_fields_customer_id, $customerId);
    }

    public function getSessionId(): ?string
    {
        return $this->getData(self::schema_fields_session_id);
    }

    public function setSessionId(?string $sessionId): static
    {
        return $this->setData(self::schema_fields_session_id, $sessionId);
    }

    public function getEmail(): ?string
    {
        return $this->getData(self::schema_fields_email);
    }

    public function setEmail(?string $email): static
    {
        return $this->setData(self::schema_fields_email, $email);
    }

    public function getTargetLocale(): string
    {
        return (string)$this->getData(self::schema_fields_target_locale);
    }

    public function setTargetLocale(string $targetLocale): static
    {
        return $this->setData(self::schema_fields_target_locale, $targetLocale);
    }
}
