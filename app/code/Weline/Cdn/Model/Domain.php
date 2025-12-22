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
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

/**
 * CDN域名模型
 * 
 * @package Weline_Cdn
 */
class Domain extends Model
{
    public const table = 'cdn_domain';
    
    /**
     * Primary key
     */
    public string $_primary_key = 'domain_id';
    
    /**
     * Primary keys
     */
    public array $_unit_primary_keys = ['domain_id'];
    
    /**
     * Field name constants
     */
    public const fields_DOMAIN_ID = 'domain_id';
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
     * Initialize model
     */
    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    /**
     * 获取主键字段名
     * 
     * @return string
     */
    public function getIdFieldName(): string
    {
        return self::fields_DOMAIN_ID;
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
        if ($setup->tableExist() === false) {
            $setup->createTable('CDN域名表')
                ->addColumn(self::fields_DOMAIN_ID, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'primary key auto_increment', '域名ID')
                ->addColumn(self::fields_SITE_ID, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'not null', '网站ID')
                ->addColumn(self::fields_ADAPTER, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 50, 'not null', '适配器代码')
                ->addColumn(self::fields_ZONE_ID, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 128, 'not null', 'Zone ID')
                ->addColumn(self::fields_DOMAIN_NAME, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 255, 'not null', '域名名称')
                ->addColumn(self::fields_ACCOUNT_ID, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'null', '账户ID')
                ->addColumn(self::fields_INHERIT_DEFAULT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, 1, 'default 1', '是否继承默认账户')
                ->addColumn(self::fields_CREDENTIALS, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, 'null', '自定义凭据JSON')
                ->addColumn(self::fields_RULES_OVERRIDE, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, 'null', '规则覆盖JSON')
                ->addColumn(self::fields_WARMUP_INTERVAL_SECONDS, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 300', '预热间隔秒数')
                ->addColumn(self::fields_ENABLED, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, 1, 'default 1', '是否启用')
                ->addColumn(self::fields_CREATED_AT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '创建时间')
                ->addColumn(self::fields_UPDATED_AT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '更新时间')
                ->addIndex(\Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_KEY, 'idx_site_id', self::fields_SITE_ID, '站点ID索引')
                ->addIndex(\Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_KEY, 'idx_adapter', self::fields_ADAPTER, '适配器索引')
                ->addIndex(\Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_KEY, 'idx_enabled', self::fields_ENABLED, '启用状态索引')
                ->addIndex(\Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_KEY, 'idx_domain_name', self::fields_DOMAIN_NAME, '域名索引')
                ->create();
        }
    }

    /**
     * 获取凭据数组
     * 
     * @return array
     */
    public function getCredentialsArray(): array
    {
        $credentials = $this->getData(self::fields_CREDENTIALS);
        if (is_string($credentials)) {
            $decoded = json_decode($credentials, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($credentials) ? $credentials : [];
    }

    /**
     * 设置凭据数组
     * 
     * @param array $credentials
     * @return self
     */
    public function setCredentialsArray(array $credentials): self
    {
        $this->setData(self::fields_CREDENTIALS, json_encode($credentials, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    /**
     * 获取规则覆盖数组
     * 
     * @return array
     */
    public function getRulesOverrideArray(): array
    {
        $rules = $this->getData(self::fields_RULES_OVERRIDE);
        if (is_string($rules)) {
            $decoded = json_decode($rules, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($rules) ? $rules : [];
    }

    /**
     * 设置规则覆盖数组
     * 
     * @param array $rules
     * @return self
     */
    public function setRulesOverrideArray(array $rules): self
    {
        $this->setData(self::fields_RULES_OVERRIDE, json_encode($rules, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    /**
     * 检查是否启用
     * 
     * @return bool
     */
    public function isEnabled(): bool
    {
        return (int)$this->getData(self::fields_ENABLED) === 1;
    }

    /**
     * 检查是否继承默认账户
     * 
     * @return bool
     */
    public function isInheritDefault(): bool
    {
        return (int)$this->getData(self::fields_INHERIT_DEFAULT) === 1;
    }

    /**
     * 保存前处理
     * 
     * @return self
     */
    public function beforeSave(): self
    {
        $now = time();
        if (!$this->getData(self::fields_CREATED_AT)) {
            $this->setData(self::fields_CREATED_AT, $now);
        }
        $this->setData(self::fields_UPDATED_AT, $now);
        return parent::beforeSave();
    }
}

