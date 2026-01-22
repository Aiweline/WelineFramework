<?php

declare(strict_types=1);

/*
 * AWS Domains 管理模块
 * AWS 配置模型
 */

namespace Aws\Domains\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * AWS 配置模型
 * 存储 AWS 访问密钥和区域配置
 */
class AwsConfig extends Model
{
    public const table = 'aws_domains_config';

    public string $_primary_key = 'config_id';
    public array $_unit_primary_keys = ['config_id'];

    // 字段常量
    public const fields_CONFIG_ID = 'config_id';
    public const fields_NAME = 'name';
    public const fields_ACCESS_KEY_ID = 'access_key_id';
    public const fields_SECRET_ACCESS_KEY = 'secret_access_key';
    public const fields_REGION = 'region';
    public const fields_IS_DEFAULT = 'is_default';
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_DESCRIPTION = 'description';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    // AWS 支持 Route 53 Domains 的区域（目前仅支持 us-east-1）
    public const SUPPORTED_REGIONS = [
        'us-east-1' => 'US East (N. Virginia)',
    ];

    // 状态常量
    public const STATUS_ACTIVE = 1;
    public const STATUS_INACTIVE = 0;

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::fields_CONFIG_ID;
    }

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist() === false) {
            $setup->createTable('AWS域名配置表')
                ->addColumn(
                    self::fields_CONFIG_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'primary key auto_increment',
                    '配置ID'
                )
                ->addColumn(
                    self::fields_NAME,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null',
                    '配置名称'
                )
                ->addColumn(
                    self::fields_ACCESS_KEY_ID,
                    TableInterface::column_type_VARCHAR,
                    128,
                    'not null',
                    'AWS Access Key ID'
                )
                ->addColumn(
                    self::fields_SECRET_ACCESS_KEY,
                    TableInterface::column_type_VARCHAR,
                    256,
                    'not null',
                    'AWS Secret Access Key（加密存储）'
                )
                ->addColumn(
                    self::fields_REGION,
                    TableInterface::column_type_VARCHAR,
                    50,
                    "default 'us-east-1'",
                    'AWS区域'
                )
                ->addColumn(
                    self::fields_IS_DEFAULT,
                    TableInterface::column_type_INTEGER,
                    1,
                    'default 0',
                    '是否默认配置'
                )
                ->addColumn(
                    self::fields_IS_ACTIVE,
                    TableInterface::column_type_INTEGER,
                    1,
                    'default 1',
                    '是否启用'
                )
                ->addColumn(
                    self::fields_DESCRIPTION,
                    TableInterface::column_type_TEXT,
                    0,
                    '',
                    '备注说明'
                )
                ->addColumn(
                    self::fields_CREATED_AT,
                    TableInterface::column_type_DATETIME,
                    0,
                    '',
                    '创建时间'
                )
                ->addColumn(
                    self::fields_UPDATED_AT,
                    TableInterface::column_type_DATETIME,
                    0,
                    '',
                    '更新时间'
                )
                ->addIndex(
                    TableInterface::index_type_UNIQUE,
                    'uk_name',
                    [self::fields_NAME],
                    '配置名称唯一索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_active_default',
                    [self::fields_IS_ACTIVE, self::fields_IS_DEFAULT],
                    '启用+默认索引'
                )
                ->create();
        }
    }

    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 预留升级逻辑
    }

    /**
     * 获取默认配置
     */
    public static function getDefaultConfig(): ?self
    {
        $model = new self();
        $model->where(self::fields_IS_ACTIVE, self::STATUS_ACTIVE)
            ->where(self::fields_IS_DEFAULT, 1)
            ->find();

        return $model->getId() ? $model : null;
    }

    /**
     * 获取所有启用的配置
     */
    public static function getActiveConfigs(): array
    {
        $model = new self();
        return $model->where(self::fields_IS_ACTIVE, self::STATUS_ACTIVE)
            ->order(self::fields_IS_DEFAULT, 'DESC')
            ->order(self::fields_CREATED_AT, 'DESC')
            ->select()
            ->fetchArray();
    }

    public function isActive(): bool
    {
        return (int)$this->getData(self::fields_IS_ACTIVE) === self::STATUS_ACTIVE;
    }

    public function isDefault(): bool
    {
        return (int)$this->getData(self::fields_IS_DEFAULT) === 1;
    }

    /**
     * 设置为默认配置（同时取消其他默认）
     */
    public function setAsDefault(): self
    {
        // 取消其他默认配置
        $this->reset()
            ->where(self::fields_IS_DEFAULT, 1)
            ->update([self::fields_IS_DEFAULT => 0]);

        $this->setData(self::fields_IS_DEFAULT, 1);
        return $this;
    }

    public function save_before(): void
    {
        $now = date('Y-m-d H:i:s');
        if (!$this->getId()) {
            $this->setData(self::fields_CREATED_AT, $now);
        }
        $this->setData(self::fields_UPDATED_AT, $now);
    }

    /**
     * 获取区域显示名称
     */
    public function getRegionDisplayName(): string
    {
        $region = $this->getData(self::fields_REGION) ?: 'us-east-1';
        return self::SUPPORTED_REGIONS[$region] ?? $region;
    }
}
