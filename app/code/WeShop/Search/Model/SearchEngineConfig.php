<?php

declare(strict_types=1);

namespace WeShop\Search\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 搜索引擎配置模型
 * 支持scope级别的配置隔离
 */
class SearchEngineConfig extends Model
{
    public const table = 'weshop_search_engine_config';
    public const primary_key = 'config_id';
    public string $indexer = 'search_engine_config_indexer';
    
    public const fields_ID = 'config_id';
    public const fields_ENGINE_TYPE = 'engine_type';
    public const fields_SCOPE = 'scope';
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_CONFIG_DATA = 'config_data';
    public const fields_PRIORITY = 'priority';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';
    
    // 搜索引擎类型常量
    public const ENGINE_MYSQL = 'mysql';
    public const ENGINE_ELASTICSEARCH = 'elasticsearch';
    public const ENGINE_ALGOLIA = 'algolia';
    public const ENGINE_SOLR = 'solr';
    public const ENGINE_MEILISEARCH = 'meilisearch';
    
    public array $_unit_primary_keys = ['config_id'];
    public array $_index_sort_keys = ['scope', 'engine_type', 'is_active', 'priority'];
    
    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
        
        // 安装默认配置和驱动注册
        try {
            /** @var \WeShop\Search\Setup\InstallData $installData */
            $installData = \Weline\Framework\Manager\ObjectManager::getInstance(\WeShop\Search\Setup\InstallData::class);
            $installData->install();
        } catch (\Exception $e) {
            error_log("搜索模块安装数据失败: " . $e->getMessage());
        }
    }
    
    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $this->install($setup, $context);
        }
    }
    
    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('搜索引擎配置表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'auto_increment primary key', '配置ID')
                ->addColumn(self::fields_ENGINE_TYPE, TableInterface::column_type_VARCHAR, 50, 'not null', '搜索引擎类型')
                ->addColumn(self::fields_SCOPE, TableInterface::column_type_VARCHAR, 100, 'not null default "default"', '作用域')
                ->addColumn(self::fields_IS_ACTIVE, TableInterface::column_type_SMALLINT, 1, 'not null default 1', '是否启用')
                ->addColumn(self::fields_CONFIG_DATA, TableInterface::column_type_TEXT, 0, '', '配置数据(JSON)')
                ->addColumn(self::fields_PRIORITY, TableInterface::column_type_INTEGER, 0, 'not null default 0', '优先级')
                ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP', '创建时间')
                ->addColumn(self::fields_UPDATED_AT, TableInterface::column_type_DATETIME, 0, 'not null default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP', '更新时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_scope', self::fields_SCOPE, '作用域索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_engine_type', self::fields_ENGINE_TYPE, '引擎类型索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_is_active', self::fields_IS_ACTIVE, '启用状态索引')
                ->addIndex(TableInterface::index_type_UNIQUE, 'uk_scope_engine', [self::fields_SCOPE, self::fields_ENGINE_TYPE], '作用域+引擎唯一索引')
                ->create();
        }
    }
    
    /**
     * 获取指定scope的搜索引擎配置
     * 
     * @param string $scope 作用域
     * @param bool $activeOnly 是否只获取启用的
     * @return array
     */
    public function getConfigByScope(string $scope = 'default', bool $activeOnly = true): array
    {
        $this->clear();
        $this->where(self::fields_SCOPE, $scope);
        
        if ($activeOnly) {
            $this->where(self::fields_IS_ACTIVE, 1);
        }
        
        $this->order(self::fields_PRIORITY, 'DESC')
            ->order(self::fields_ID, 'DESC');
        
        return $this->select()->fetchArray();
    }
    
    /**
     * 获取当前激活的搜索引擎配置
     * 
     * @param string $scope 作用域
     * @return array|null
     */
    public function getActiveEngineConfig(string $scope = 'default'): ?array
    {
        $configs = $this->getConfigByScope($scope, true);
        
        if (empty($configs)) {
            // 如果没有scope配置，回退到default
            if ($scope !== 'default') {
                return $this->getActiveEngineConfig('default');
            }
            return null;
        }
        
        // 返回优先级最高的配置
        return $configs[0] ?? null;
    }
    
    /**
     * 保存配置
     * 
     * @param string $engineType 引擎类型
     * @param string $scope 作用域
     * @param array $configData 配置数据
     * @param bool $isActive 是否启用
     * @param int $priority 优先级
     * @return bool
     */
    public function saveConfig(string $engineType, string $scope, array $configData, bool $isActive = true, int $priority = 0): bool
    {
        $this->clear();
        
        // 查找是否已存在
        $existing = $this->where(self::fields_ENGINE_TYPE, $engineType)
            ->where(self::fields_SCOPE, $scope)
            ->find()
            ->fetch();
        
        if ($existing->getId()) {
            // 更新
            $existing->setData(self::fields_CONFIG_DATA, json_encode($configData, JSON_UNESCAPED_UNICODE))
                ->setData(self::fields_IS_ACTIVE, $isActive ? 1 : 0)
                ->setData(self::fields_PRIORITY, $priority)
                ->save();
        } else {
            // 新增
            $this->setData(self::fields_ENGINE_TYPE, $engineType)
                ->setData(self::fields_SCOPE, $scope)
                ->setData(self::fields_CONFIG_DATA, json_encode($configData, JSON_UNESCAPED_UNICODE))
                ->setData(self::fields_IS_ACTIVE, $isActive ? 1 : 0)
                ->setData(self::fields_PRIORITY, $priority)
                ->save();
        }
        
        return true;
    }
    
    /**
     * 获取配置数据（解析JSON）
     * 
     * @return array
     */
    public function getConfigData(): array
    {
        $configData = $this->getData(self::fields_CONFIG_DATA);
        if (empty($configData)) {
            return [];
        }
        
        $decoded = json_decode($configData, true);
        return is_array($decoded) ? $decoded : [];
    }
    
    /**
     * 获取所有可用的scope列表
     * 
     * @return array
     */
    public function getAllScopes(): array
    {
        $this->clear();
        $this->fields('DISTINCT ' . self::fields_SCOPE)
            ->order(self::fields_SCOPE, 'ASC');
        
        $scopes = $this->select()->fetchArray();
        return array_column($scopes, self::fields_SCOPE);
    }
}
