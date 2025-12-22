<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\CustomerService\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class ChatSession extends Model
{
    public const table = 'chat_session';
    public const fields_ID = 'session_id';
    public const fields_customer_id = 'customer_id';
    public const fields_agent_id = 'agent_id';
    public const fields_session_token = 'session_token';
    public const fields_customer_locale = 'customer_locale';
    public const fields_agent_locale = 'agent_locale';
    public const fields_status = 'status';
    public const fields_created_at = 'created_at';
    public const fields_updated_at = 'updated_at';

    public const STATUS_WAITING = 'waiting';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_CLOSED = 'closed';

    public function _init(): void
    {
        $this->_primary_key = self::fields_ID;
        $this->_table = self::table;
    }

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 升级逻辑
    }

    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('聊天会话表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'primary key auto_increment', '会话ID')
                ->addColumn(self::fields_customer_id, TableInterface::column_type_INTEGER, 0, '', '客户ID')
                ->addColumn(self::fields_agent_id, TableInterface::column_type_INTEGER, 0, '', '客服ID')
                ->addColumn(self::fields_session_token, TableInterface::column_type_VARCHAR, 255, 'not null', '会话令牌')
                ->addColumn(self::fields_customer_locale, TableInterface::column_type_VARCHAR, 20, 'not null default "zh_Hans_CN"', '客户语言')
                ->addColumn(self::fields_agent_locale, TableInterface::column_type_VARCHAR, 20, 'not null default "zh_Hans_CN"', '客服语言')
                ->addColumn(self::fields_status, TableInterface::column_type_VARCHAR, 20, 'not null default "waiting"', '状态')
                ->addColumn(self::fields_created_at, TableInterface::column_type_DATETIME, 0, '', '创建时间')
                ->addColumn(self::fields_updated_at, TableInterface::column_type_DATETIME, 0, '', '更新时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_customer_id', self::fields_customer_id, '客户ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_agent_id', self::fields_agent_id, '客服ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_session_token', self::fields_session_token, '会话令牌索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_status', self::fields_status, '状态索引')
                ->create();
        }
    }

    public function getCustomerId(): ?int
    {
        $id = $this->getData(self::fields_customer_id);
        return $id ? (int)$id : null;
    }

    public function setCustomerId(?int $customerId): static
    {
        return $this->setData(self::fields_customer_id, $customerId);
    }

    public function getAgentId(): ?int
    {
        $id = $this->getData(self::fields_agent_id);
        return $id ? (int)$id : null;
    }

    public function setAgentId(?int $agentId): static
    {
        return $this->setData(self::fields_agent_id, $agentId);
    }

    public function getSessionToken(): string
    {
        return (string)$this->getData(self::fields_session_token);
    }

    public function setSessionToken(string $sessionToken): static
    {
        return $this->setData(self::fields_session_token, $sessionToken);
    }

    public function getCustomerLocale(): string
    {
        return (string)$this->getData(self::fields_customer_locale);
    }

    public function setCustomerLocale(string $customerLocale): static
    {
        return $this->setData(self::fields_customer_locale, $customerLocale);
    }

    public function getAgentLocale(): string
    {
        return (string)$this->getData(self::fields_agent_locale);
    }

    public function setAgentLocale(string $agentLocale): static
    {
        return $this->setData(self::fields_agent_locale, $agentLocale);
    }

    public function getStatus(): string
    {
        return (string)$this->getData(self::fields_status);
    }

    public function setStatus(string $status): static
    {
        return $this->setData(self::fields_status, $status);
    }
}

