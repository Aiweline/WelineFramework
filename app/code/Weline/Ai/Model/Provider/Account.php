<?php

declare(strict_types=1);

namespace Weline\Ai\Model\Provider;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

/**
 * AI Provider Account Model
 * 
 * 管理AI供应商账户，包括API凭证、余额、连通性状态等
 * 
 * @package Weline_Ai
 */
class Account extends Model
{
    public const table = 'ai_provider_account';
    
    /**
     * Primary keys
     */
    public array $_unit_primary_keys = ['id'];
    
    /**
     * Index sort keys
     */
    public array $_index_sort_keys = ['id', 'provider_code', 'is_active'];
    
    /**
     * Field name constants
     */
    public const fields_ID = 'id';
    public const fields_PROVIDER_CODE = 'provider_code';  // 供应商代码：openai, deepseek, google等
    public const fields_ACCOUNT_NAME = 'account_name';   // 账户名称
    public const fields_API_KEY = 'api_key';            // API密钥（加密存储）
    public const fields_API_SECRET = 'api_secret';      // API密钥（如果需要）
    public const fields_BASE_URL = 'base_url';          // API基础URL
    public const fields_PROXY_CONFIG = 'proxy_config';  // 代理配置JSON
    public const fields_BALANCE = 'balance';            // 账户余额
    public const fields_CURRENCY = 'currency';          // 货币单位
    public const fields_TOTAL_SPENT = 'total_spent';    // 总花费
    public const fields_IS_ACTIVE = 'is_active';        // 是否激活
    public const fields_IS_DEFAULT = 'is_default';      // 是否为该供应商的默认账户
    public const fields_CONNECTION_STATUS = 'connection_status';  // 连通状态：pending, success, failed
    public const fields_CONNECTION_TEST_TIME = 'connection_test_time';  // 最后测试时间
    public const fields_CONNECTION_TEST_MESSAGE = 'connection_test_message';  // 测试消息
    public const fields_CONFIG = 'config';              // 额外配置JSON
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    /**
     * Connection status constants
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

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
        if ($setup->tableExist() === false) {
            $setup->createTable('AI供应商账户表')
                ->addColumn(self::fields_ID, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'primary key auto_increment', 'ID')
                ->addColumn(self::fields_PROVIDER_CODE, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 50, 'not null', '供应商代码')
                ->addColumn(self::fields_ACCOUNT_NAME, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 100, 'not null', '账户名称')
                ->addColumn(self::fields_API_KEY, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, 'not null', 'API密钥')
                ->addColumn(self::fields_API_SECRET, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, 'null', 'API密钥')
                ->addColumn(self::fields_BASE_URL, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 255, 'null', 'API基础URL')
                ->addColumn(self::fields_PROXY_CONFIG, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, 'null', '代理配置JSON')
                ->addColumn(self::fields_BALANCE, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DECIMAL, '10,2', 'default 0', '账户余额')
                ->addColumn(self::fields_CURRENCY, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 10, 'default \'USD\'', '货币单位')
                ->addColumn(self::fields_TOTAL_SPENT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DECIMAL, '10,2', 'default 0', '总花费')
                ->addColumn(self::fields_IS_ACTIVE, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, 1, 'default 0', '是否激活')
                ->addColumn(self::fields_IS_DEFAULT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, 1, 'default 0', '是否默认')
                ->addColumn(self::fields_CONNECTION_STATUS, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 20, 'default \'pending\'', '连通状态')
                ->addColumn(self::fields_CONNECTION_TEST_TIME, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'null', '最后测试时间')
                ->addColumn(self::fields_CONNECTION_TEST_MESSAGE, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, 'null', '测试消息')
                ->addColumn(self::fields_CONFIG, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, 'null', '额外配置JSON')
                ->addColumn(self::fields_CREATED_AT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '创建时间')
                ->addColumn(self::fields_UPDATED_AT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '更新时间')
                ->addIndex(\Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_KEY, 'idx_provider_code', self::fields_PROVIDER_CODE)
                ->addIndex(\Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_KEY, 'idx_is_active', self::fields_IS_ACTIVE)
                ->addIndex(\Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_KEY, 'idx_provider_default', self::fields_PROVIDER_CODE . ',' . self::fields_IS_DEFAULT)
                ->create();
        }
    }

    /**
     * 获取解密后的API密钥
     * 
     * @return string
     */
    public function getDecryptedApiKey(): string
    {
        $apiKey = $this->getData(self::fields_API_KEY);
        // TODO: 实现解密逻辑
        return $apiKey ?: '';
    }

    /**
     * 设置加密的API密钥
     * 
     * @param string $apiKey
     * @return self
     */
    public function setEncryptedApiKey(string $apiKey): self
    {
        // TODO: 实现加密逻辑
        $this->setData(self::fields_API_KEY, $apiKey);
        return $this;
    }

    /**
     * 获取代理配置
     * 
     * @return array
     */
    public function getProxyConfig(): array
    {
        $config = $this->getData(self::fields_PROXY_CONFIG);
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($config) ? $config : [];
    }

    /**
     * 获取额外配置
     * 
     * @return array
     */
    public function getConfig(): array
    {
        $config = $this->getData(self::fields_CONFIG);
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($config) ? $config : [];
    }

    /**
     * 检查账户是否可用
     * 
     * @return bool
     */
    public function isAvailable(): bool
    {
        return $this->getData(self::fields_IS_ACTIVE) == 1 
            && $this->getData(self::fields_CONNECTION_STATUS) === self::STATUS_SUCCESS
            && $this->getData(self::fields_BALANCE) > 0;
    }

    /**
     * 更新余额
     * 
     * @param float $amount 花费金额（正数减少余额）
     * @return self
     */
    public function updateBalance(float $amount): self
    {
        $currentBalance = (float)$this->getData(self::fields_BALANCE);
        $totalSpent = (float)$this->getData(self::fields_TOTAL_SPENT);
        
        $this->setData(self::fields_BALANCE, $currentBalance - $amount);
        $this->setData(self::fields_TOTAL_SPENT, $totalSpent + $amount);
        
        return $this;
    }
}
