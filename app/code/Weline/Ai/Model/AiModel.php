<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：<?= date('Y/m/d H:i:s') ?>

 */

namespace Weline\Ai\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * AI模型数据模型
 * 
 * 功能：
 * - 管理AI模型的基本信息
 * - 存储模型配置和代理信息
 * - 支持模型版本控制
 * - 提供模型状态管理
 */
class AiModel extends Model
{
    public const table = 'ai_model';
    
    // 字段常量
    public const fields_ID = 'id';
    public const fields_VENDOR = 'vendor';
    public const fields_MODEL_CODE = 'model_code';
    public const fields_MODEL_NAME = 'model_name';
    public const fields_MODEL_VERSION = 'model_version';
    public const fields_CONFIG_JSON = 'config_json';
    public const fields_TOKEN_PRICE_INPUT = 'token_price_input';
    public const fields_TOKEN_PRICE_OUTPUT = 'token_price_output';
    public const fields_PROXY_INFO = 'proxy_info';
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_IS_DEFAULT = 'is_default';
    public const fields_CREATED_TIME = 'created_time';
    public const fields_UPDATED_TIME = 'updated_time';

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
        // TODO: Implement upgrade() method.
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable()
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 11, 'primary key auto_increment', 'ID')
                ->addColumn(self::fields_VENDOR, TableInterface::column_type_VARCHAR, 100, 'not null', '供应商')
                ->addColumn(self::fields_MODEL_CODE, TableInterface::column_type_VARCHAR, 100, 'not null', '模型代码')
                ->addColumn(self::fields_MODEL_NAME, TableInterface::column_type_VARCHAR, 255, 'not null', '模型名称')
                ->addColumn(self::fields_MODEL_VERSION, TableInterface::column_type_VARCHAR, 50, 'not null default "1.0"', '模型版本')
                ->addColumn(self::fields_CONFIG_JSON, TableInterface::column_type_TEXT, null, 'null', '配置JSON')
                ->addColumn(self::fields_TOKEN_PRICE_INPUT, TableInterface::column_type_DECIMAL, '10,6', 'not null default 0.000000', '输入Token价格')
                ->addColumn(self::fields_TOKEN_PRICE_OUTPUT, TableInterface::column_type_DECIMAL, '10,6', 'not null default 0.000000', '输出Token价格')
                ->addColumn(self::fields_PROXY_INFO, TableInterface::column_type_TEXT, null, 'null', '代理信息JSON')
                ->addColumn(self::fields_IS_ACTIVE, TableInterface::column_type_INTEGER, 1, 'not null default 1', '是否激活')
                ->addColumn(self::fields_IS_DEFAULT, TableInterface::column_type_INTEGER, 1, 'not null default 0', '是否默认')
                ->addColumn(self::fields_CREATED_TIME, TableInterface::column_type_INTEGER, 11, 'not null', '创建时间')
                ->addColumn(self::fields_UPDATED_TIME, TableInterface::column_type_INTEGER, 11, 'not null', '更新时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_vendor', self::fields_VENDOR, '供应商索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_model_code', self::fields_MODEL_CODE, '模型代码索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_is_active', self::fields_IS_ACTIVE, '激活状态索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_is_default', self::fields_IS_DEFAULT, '默认状态索引')
                ->create();
        }
    }

    /**
     * 获取模型配置
     * 
     * @return array
     */
    public function getConfig(): array
    {
        $config = $this->getData(self::fields_CONFIG_JSON);
        return $config ? json_decode($config, true) : [];
    }

    /**
     * 设置模型配置
     * 
     * @param array $config
     * @return $this
     */
    public function setConfig(array $config): self
    {
        $this->setData(self::fields_CONFIG_JSON, json_encode($config));
        return $this;
    }

    /**
     * 获取代理信息
     * 
     * @return array
     */
    public function getProxyInfo(): array
    {
        $proxyInfo = $this->getData(self::fields_PROXY_INFO);
        return $proxyInfo ? json_decode($proxyInfo, true) : [];
    }

    /**
     * 设置代理信息
     * 
     * @param array $proxyInfo
     * @return $this
     */
    public function setProxyInfo(array $proxyInfo): self
    {
        $this->setData(self::fields_PROXY_INFO, json_encode($proxyInfo));
        return $this;
    }

    /**
     * 检查是否为激活状态
     * 
     * @return bool
     */
    public function isActive(): bool
    {
        return (bool)$this->getData(self::fields_IS_ACTIVE);
    }

    /**
     * 检查是否为默认模型
     * 
     * @return bool
     */
    public function isDefault(): bool
    {
        return (bool)$this->getData(self::fields_IS_DEFAULT);
    }

    /**
     * 获取完整的模型标识
     * 
     * @return string
     */
    public function getFullModelCode(): string
    {
        return $this->getData(self::fields_VENDOR) . '/' . $this->getData(self::fields_MODEL_CODE);
    }

    /**
     * 获取模型名称
     * 
     * @return string
     */
    public function getName(): string
    {
        return $this->getData(self::fields_MODEL_NAME) ?? '';
    }

    /**
     * 获取供应商名称
     * 
     * @return string
     */
    public function getVendor(): string
    {
        return $this->getData(self::fields_VENDOR) ?? '';
    }

    /**
     * 获取模型状态
     * 
     * @return string
     */
    public function getStatus(): string
    {
        return $this->isActive() ? 'active' : 'inactive';
    }

    /**
     * 获取模型代码
     * 
     * @return string
     */
    public function getModelCode(): string
    {
        return $this->getData(self::fields_MODEL_CODE) ?? '';
    }

    /**
     * 保存前的数据处理
     * 
     * @return $this
     */
    public function beforeSave(): self
    {
        parent::beforeSave();
        
        $currentTime = time();
        if (!$this->getId()) {
            $this->setData(self::fields_CREATED_TIME, $currentTime);
        }
        $this->setData(self::fields_UPDATED_TIME, $currentTime);
        
        return $this;
    }
}
