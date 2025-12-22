<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Payment\Model;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class PaymentMethod extends AbstractModel
{
    public const table = 'weline_payment_method';
    
    public const fields_ID = 'method_id';
    public const fields_CODE = 'code';
    public const fields_NAME = 'name';
    public const fields_PROVIDER_MODULE = 'provider_module';
    public const fields_PROVIDER_CLASS = 'provider_class';
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_SORT_ORDER = 'sort_order';
    public const fields_CONFIG = 'config';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['method_id'];

    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['method_id', 'code', 'is_active', 'sort_order'];

    /**
     * 初始化模型
     */
    public function _init(): void
    {
        $this->_primary_key = self::fields_ID;
    }

    /**
     * 安装表结构
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('支付方式表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'primary key auto_increment',
                    '支付方式ID'
                )
                ->addColumn(
                    self::fields_CODE,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'not null unique',
                    '支付方式代码（唯一）'
                )
                ->addColumn(
                    self::fields_NAME,
                    TableInterface::column_type_VARCHAR,
                    100,
                    'not null',
                    '支付方式名称'
                )
                ->addColumn(
                    self::fields_PROVIDER_MODULE,
                    TableInterface::column_type_VARCHAR,
                    100,
                    'not null',
                    '支付提供商模块名'
                )
                ->addColumn(
                    self::fields_PROVIDER_CLASS,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null',
                    '支付提供商类名'
                )
                ->addColumn(
                    self::fields_IS_ACTIVE,
                    TableInterface::column_type_INTEGER,
                    1,
                    'default 0',
                    '是否启用：1-是，0-否'
                )
                ->addColumn(
                    self::fields_SORT_ORDER,
                    TableInterface::column_type_INTEGER,
                    null,
                    'default 0',
                    '排序'
                )
                ->addColumn(
                    self::fields_CONFIG,
                    TableInterface::column_type_TEXT,
                    null,
                    'default null',
                    '配置信息（JSON）'
                )
                ->addColumn(
                    self::fields_CREATED_AT,
                    TableInterface::column_type_DATETIME,
                    null,
                    'default CURRENT_TIMESTAMP',
                    '创建时间'
                )
                ->addColumn(
                    self::fields_UPDATED_AT,
                    TableInterface::column_type_DATETIME,
                    null,
                    'default CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                    '更新时间'
                )
                ->addIndex(
                    TableInterface::index_type_UNIQUE,
                    'idx_code',
                    self::fields_CODE,
                    '支付方式代码唯一索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_is_active',
                    self::fields_IS_ACTIVE,
                    '启用状态索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_sort_order',
                    self::fields_SORT_ORDER,
                    '排序索引'
                )
                ->create();
        }
    }

    /**
     * 设置表结构（开发模式）
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * 升级表结构
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 升级逻辑
    }

    /**
     * 是否启用
     * 
     * @return bool
     */
    public function isActive(): bool
    {
        return (bool)$this->getData(self::fields_IS_ACTIVE);
    }

    /**
     * 获取配置
     * 
     * @return array
     */
    public function getConfigData(): array
    {
        $config = $this->getData(self::fields_CONFIG);
        if (empty($config)) {
            return [];
        }
        if (is_string($config)) {
            return json_decode($config, true) ?? [];
        }
        return $config;
    }

    /**
     * 设置配置
     * 
     * @param array $config
     * @return $this
     */
    public function setConfigData(array $config): static
    {
        return $this->setData(self::fields_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * 获取支持的优惠方式代码列表
     * 
     * @return array
     */
    public function getSupportedDiscountActions(): array
    {
        $config = $this->getConfigData();
        return $config['supported_discount_actions'] ?? [];
    }
    
    /**
     * 设置支持的优惠方式代码列表
     * 
     * @param array $actions 优惠方式代码数组
     * @return $this
     */
    public function setSupportedDiscountActions(array $actions): static
    {
        $config = $this->getConfigData();
        $config['supported_discount_actions'] = $actions;
        return $this->setConfigData($config);
    }
    
    /**
     * 检查是否支持特定优惠方式
     * 
     * @param string $actionCode 优惠方式代码（如：discount_fixed_amount）
     * @return bool
     */
    public function supportsDiscountAction(string $actionCode): bool
    {
        $supported = $this->getSupportedDiscountActions();
        
        // 如果为空数组，表示支持所有优惠方式（向后兼容）
        if (empty($supported)) {
            // 尝试从支付提供商获取默认支持
            $providerSupported = $this->getProviderSupportedActions();
            if ($providerSupported !== null) {
                // 如果提供商返回null，表示不支持任何
                if (empty($providerSupported)) {
                    return false;
                }
                // 如果提供商返回空数组，表示支持所有
                return in_array($actionCode, $providerSupported, true);
            }
            // 如果提供商也未实现，默认支持所有（向后兼容）
            return true;
        }
        
        return in_array($actionCode, $supported, true);
    }
    
    /**
     * 从支付提供商获取支持的优惠方式
     * 
     * @return array|null
     */
    private function getProviderSupportedActions(): ?array
    {
        try {
            $providerClass = $this->getData(self::fields_PROVIDER_CLASS);
            if (empty($providerClass) || !class_exists($providerClass)) {
                return null;
            }
            
            $provider = \Weline\Framework\Manager\ObjectManager::getInstance($providerClass);
            if ($provider instanceof \Weline\Payment\Interface\PaymentProviderInterface) {
                // 检查是否实现了新方法
                if (method_exists($provider, 'getSupportedDiscountActions')) {
                    return $provider->getSupportedDiscountActions();
                }
            }
        } catch (\Throwable $e) {
            // 忽略错误
        }
        
        return null;
    }
}

