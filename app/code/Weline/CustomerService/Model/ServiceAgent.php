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

class ServiceAgent extends Model
{
    public const table = 'service_agent';
    public const fields_ID = 'agent_id';
    public const fields_user_id = 'user_id';
    public const fields_name = 'name';
    public const fields_email = 'email';
    public const fields_locale = 'locale';
    public const fields_is_active = 'is_active';
    public const fields_max_sessions = 'max_sessions';
    public const fields_created_at = 'created_at';
    public const fields_updated_at = 'updated_at';

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
            $setup->createTable('客服人员表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'primary key auto_increment', '客服ID')
                ->addColumn(self::fields_user_id, TableInterface::column_type_INTEGER, 0, 'not null', '关联后台用户ID')
                ->addColumn(self::fields_name, TableInterface::column_type_VARCHAR, 100, 'not null', '客服名称')
                ->addColumn(self::fields_email, TableInterface::column_type_VARCHAR, 255, 'not null', '邮箱')
                ->addColumn(self::fields_locale, TableInterface::column_type_VARCHAR, 20, 'not null default "zh_Hans_CN"', '客服语言')
                ->addColumn(self::fields_is_active, TableInterface::column_type_INTEGER, 1, 'not null default 1', '是否激活')
                ->addColumn(self::fields_max_sessions, TableInterface::column_type_INTEGER, 0, 'not null default 10', '最大并发会话数')
                ->addColumn(self::fields_created_at, TableInterface::column_type_DATETIME, 0, '', '创建时间')
                ->addColumn(self::fields_updated_at, TableInterface::column_type_DATETIME, 0, '', '更新时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_user_id', self::fields_user_id, '用户ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_is_active', self::fields_is_active, '激活状态索引')
                ->create();
        }
    }

    public function getUserId(): int
    {
        return (int)$this->getData(self::fields_user_id);
    }

    public function setUserId(int $userId): static
    {
        return $this->setData(self::fields_user_id, $userId);
    }

    public function getName(): string
    {
        return (string)$this->getData(self::fields_name);
    }

    public function setName(string $name): static
    {
        return $this->setData(self::fields_name, $name);
    }

    public function getEmail(): string
    {
        return (string)$this->getData(self::fields_email);
    }

    public function setEmail(string $email): static
    {
        return $this->setData(self::fields_email, $email);
    }

    public function getLocale(): string
    {
        return (string)$this->getData(self::fields_locale);
    }

    public function setLocale(string $locale): static
    {
        return $this->setData(self::fields_locale, $locale);
    }

    public function getIsActive(): bool
    {
        return (bool)$this->getData(self::fields_is_active);
    }

    public function setIsActive(bool $isActive): static
    {
        return $this->setData(self::fields_is_active, $isActive ? 1 : 0);
    }

    public function getMaxSessions(): int
    {
        return (int)$this->getData(self::fields_max_sessions);
    }

    public function setMaxSessions(int $maxSessions): static
    {
        return $this->setData(self::fields_max_sessions, $maxSessions);
    }
}

