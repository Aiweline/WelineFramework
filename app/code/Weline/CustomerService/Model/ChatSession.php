<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\CustomerService\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '聊天会话表')]
#[Index(name: 'idx_customer_id', columns: ['customer_id'])]
#[Index(name: 'idx_agent_id', columns: ['agent_id'])]
#[Index(name: 'idx_session_token', columns: ['session_token'])]
#[Index(name: 'idx_status', columns: ['status'])]
class ChatSession extends Model
{
    public const schema_table = 'chat_session';
    public const schema_primary_key = 'session_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '会话ID')]
    public const schema_fields_ID = 'session_id';
    #[Col(type: 'int', nullable: true, comment: '客户ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';
    #[Col(type: 'int', nullable: true, comment: '客服ID')]
    public const schema_fields_AGENT_ID = 'agent_id';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '会话令牌')]
    public const schema_fields_SESSION_TOKEN = 'session_token';
    #[Col(type: 'varchar', length: 20, nullable: false, default: 'zh_Hans_CN', comment: '客户语言')]
    public const schema_fields_CUSTOMER_LOCALE = 'customer_locale';
    #[Col(type: 'varchar', length: 20, nullable: false, default: 'zh_Hans_CN', comment: '客服语言')]
    public const schema_fields_AGENT_LOCALE = 'agent_locale';
    #[Col(type: 'varchar', length: 20, nullable: false, default: 'waiting', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public const STATUS_WAITING = 'waiting';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_CLOSED = 'closed';

    public function _init(): void
    {
    }

    public function getCustomerId(): ?int
    {
        $id = $this->getData(self::schema_fields_CUSTOMER_ID);
        return $id ? (int)$id : null;
    }

    public function setCustomerId(?int $customerId): static
    {
        return $this->setData(self::schema_fields_CUSTOMER_ID, $customerId);
    }

    public function getAgentId(): ?int
    {
        $id = $this->getData(self::schema_fields_AGENT_ID);
        return $id ? (int)$id : null;
    }

    public function setAgentId(?int $agentId): static
    {
        return $this->setData(self::schema_fields_AGENT_ID, $agentId);
    }

    public function getSessionToken(): string
    {
        return (string)$this->getData(self::schema_fields_SESSION_TOKEN);
    }

    public function setSessionToken(string $sessionToken): static
    {
        return $this->setData(self::schema_fields_SESSION_TOKEN, $sessionToken);
    }

    public function getCustomerLocale(): string
    {
        return (string)$this->getData(self::schema_fields_CUSTOMER_LOCALE);
    }

    public function setCustomerLocale(string $customerLocale): static
    {
        return $this->setData(self::schema_fields_CUSTOMER_LOCALE, $customerLocale);
    }

    public function getAgentLocale(): string
    {
        return (string)$this->getData(self::schema_fields_AGENT_LOCALE);
    }

    public function setAgentLocale(string $agentLocale): static
    {
        return $this->setData(self::schema_fields_AGENT_LOCALE, $agentLocale);
    }

    public function getStatus(): string
    {
        return (string)$this->getData(self::schema_fields_STATUS);
    }

    public function setStatus(string $status): static
    {
        return $this->setData(self::schema_fields_STATUS, $status);
    }
}
