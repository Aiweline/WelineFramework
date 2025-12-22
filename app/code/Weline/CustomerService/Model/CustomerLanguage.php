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

class CustomerLanguage extends Model
{
    public const table = 'customer_language';
    public const fields_ID = 'language_id';
    public const fields_customer_id = 'customer_id';
    public const fields_session_id = 'session_id';
    public const fields_email = 'email';
    public const fields_target_locale = 'target_locale';
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
            $setup->createTable('客户语言配置表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'primary key auto_increment', '语言配置ID')
                ->addColumn(self::fields_customer_id, TableInterface::column_type_INTEGER, 0, '', '客户ID')
                ->addColumn(self::fields_session_id, TableInterface::column_type_VARCHAR, 255, '', '会话ID')
                ->addColumn(self::fields_email, TableInterface::column_type_VARCHAR, 255, '', '邮箱')
                ->addColumn(self::fields_target_locale, TableInterface::column_type_VARCHAR, 20, 'not null default "zh_Hans_CN"', '目标语言代码')
                ->addColumn(self::fields_created_at, TableInterface::column_type_DATETIME, 0, '', '创建时间')
                ->addColumn(self::fields_updated_at, TableInterface::column_type_DATETIME, 0, '', '更新时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_customer_id', self::fields_customer_id, '客户ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_session_id', self::fields_session_id, '会话ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_email', self::fields_email, '邮箱索引')
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

    public function getSessionId(): ?string
    {
        return $this->getData(self::fields_session_id);
    }

    public function setSessionId(?string $sessionId): static
    {
        return $this->setData(self::fields_session_id, $sessionId);
    }

    public function getEmail(): ?string
    {
        return $this->getData(self::fields_email);
    }

    public function setEmail(?string $email): static
    {
        return $this->setData(self::fields_email, $email);
    }

    public function getTargetLocale(): string
    {
        return (string)$this->getData(self::fields_target_locale);
    }

    public function setTargetLocale(string $targetLocale): static
    {
        return $this->setData(self::fields_target_locale, $targetLocale);
    }
}

