<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface;

/**
 * CDN域名模型
 */
class Domain extends Model
{
    // 字段常量
    public const fields_ID = 'domain_id';
    public const fields_SITE_ID = 'site_id';
    public const fields_ADAPTER = 'adapter';
    public const fields_ZONE_ID = 'zone_id';
    public const fields_DOMAIN_NAME = 'domain_name';
    public const fields_ACCOUNT_ID = 'account_id';
    public const fields_INHERIT_DEFAULT = 'inherit_default';
    public const fields_CREDENTIALS = 'credentials';
    public const fields_RULES_OVERRIDE = 'rules_override';
    public const fields_WARMUP_INTERVAL_SECONDS = 'warmup_interval_seconds';
    public const fields_ENABLED = 'enabled';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    /**
     * @inheritDoc
     */
    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    /**
     * 获取主键字段名
     */
    public function getIdFieldName(): string
    {
        return self::fields_ID;
    }

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 升级逻辑
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }

        $setup->createTable('CDN域名表')
            ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 11, 'primary key auto_increment', '域名ID')
            ->addColumn(self::fields_SITE_ID, TableInterface::column_type_INTEGER, 11, 'not null', '网站ID')
            ->addColumn(self::fields_ADAPTER, TableInterface::column_type_VARCHAR, 50, 'not null', '适配器代码')
            ->addColumn(self::fields_ZONE_ID, TableInterface::column_type_VARCHAR, 128, 'not null', 'Zone ID')
            ->addColumn(self::fields_DOMAIN_NAME, TableInterface::column_type_VARCHAR, 255, 'not null', '域名名称')
            ->addColumn(self::fields_ACCOUNT_ID, TableInterface::column_type_INTEGER, 11, '', '账户ID')
            ->addColumn(self::fields_INHERIT_DEFAULT, TableInterface::column_type_INTEGER, 1, 'default 1', '是否继承默认账户')
            ->addColumn(self::fields_CREDENTIALS, TableInterface::column_type_TEXT, null, '', '自定义凭据JSON')
            ->addColumn(self::fields_RULES_OVERRIDE, TableInterface::column_type_TEXT, null, '', '规则覆盖JSON')
            ->addColumn(self::fields_WARMUP_INTERVAL_SECONDS, TableInterface::column_type_INTEGER, 11, 'default 300', '预热间隔秒数')
            ->addColumn(self::fields_ENABLED, TableInterface::column_type_INTEGER, 1, 'default 1', '是否启用')
            ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_INTEGER, null, 'default 0', '创建时间')
            ->addColumn(self::fields_UPDATED_AT, TableInterface::column_type_INTEGER, null, 'default 0', '更新时间')
            ->addIndex(self::fields_SITE_ID, '', 'INDEX', 'idx_site_id')
            ->addIndex(self::fields_ADAPTER, '', 'INDEX', 'idx_adapter')
            ->addIndex(self::fields_DOMAIN_NAME, '', 'UNIQUE', 'idx_domain_name')
            ->create();
    }

    /**
     * 获取凭据（解析JSON）
     */
    public function getCredentials(): array
    {
        $credentials = $this->getData(self::fields_CREDENTIALS);
        if (empty($credentials)) {
            return [];
        }
        
        if (is_string($credentials)) {
            $decoded = json_decode($credentials, true);
            return is_array($decoded) ? $decoded : [];
        }
        
        return is_array($credentials) ? $credentials : [];
    }

    /**
     * 设置凭据（自动编码JSON）
     */
    public function setCredentials(array $credentials): self
    {
        $this->setData(self::fields_CREDENTIALS, json_encode($credentials, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    /**
     * 获取规则覆盖（解析JSON）
     */
    public function getRulesOverride(): array
    {
        $rules = $this->getData(self::fields_RULES_OVERRIDE);
        if (empty($rules)) {
            return [];
        }
        
        if (is_string($rules)) {
            $decoded = json_decode($rules, true);
            return is_array($decoded) ? $decoded : [];
        }
        
        return is_array($rules) ? $rules : [];
    }

    /**
     * 设置规则覆盖（自动编码JSON）
     */
    public function setRulesOverride(array $rules): self
    {
        $this->setData(self::fields_RULES_OVERRIDE, json_encode($rules, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    /**
     * 检查是否启用
     */
    public function isEnabled(): bool
    {
        return (bool)$this->getData(self::fields_ENABLED);
    }
}
