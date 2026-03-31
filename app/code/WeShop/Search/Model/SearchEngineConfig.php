<?php

declare(strict_types=1);

namespace WeShop\Search\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 搜索引擎配置模型
 * 支持scope级别的配置隔离
 */
#[Table(comment: '搜索引擎配置表')]
#[Index(name: 'idx_scope', columns: ['scope'], type: 'KEY', comment: '作用域索引')]
#[Index(name: 'idx_engine_type', columns: ['engine_type'], type: 'KEY', comment: '引擎类型索引')]
#[Index(name: 'idx_is_active', columns: ['is_active'], type: 'KEY', comment: '启用状态索引')]
#[Index(name: 'uk_scope_engine', columns: ['scope', 'engine_type'], type: 'UNIQUE', comment: '作用域+引擎唯一索引')]
class SearchEngineConfig extends Model
{
    public const schema_table = 'weshop_search_engine_config';
    public const schema_primary_key = 'config_id';
    public string $indexer = 'search_engine_config_indexer';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '配置ID')]
    public const schema_fields_ID = 'config_id';
    #[Col(type: 'varchar', length: 50, nullable: false, comment: '作用域')]
    public const schema_fields_SCOPE = 'scope';
    #[Col(type: 'varchar', length: 50, nullable: false, comment: '引擎类型')]
    public const schema_fields_ENGINE_TYPE = 'engine_type';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col(type: 'text', nullable: true, comment: '配置数据JSON')]
    public const schema_fields_CONFIG_DATA = 'config_data';
    #[Col(type: 'int', nullable: false, default: 0, comment: '优先级')]
    public const schema_fields_PRIORITY = 'priority';
    #[Col(type: 'datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: false, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    
    public const ENGINE_MYSQL = 'mysql';
    public const ENGINE_OPENSEARCH = 'opensearch';
    public const ENGINE_ELASTICSEARCH = 'elasticsearch';
    public const ENGINE_ALGOLIA = 'algolia';
    public const ENGINE_SOLR = 'solr';
    public const ENGINE_MEILISEARCH = 'meilisearch';
    public array $_unit_primary_keys = ['config_id'];
    public array $_index_sort_keys = ['scope', 'engine_type', 'is_active', 'priority'];
    public function getConfigByScope(string $scope = 'default', bool $activeOnly = true): array
    {
        $this->clear();
        $this->where(self::schema_fields_SCOPE, $scope);
        if ($activeOnly) {
            $this->where(self::schema_fields_IS_ACTIVE, 1);
        }
        $this->order(self::schema_fields_PRIORITY, 'DESC')->order(self::schema_fields_ID, 'DESC');
        return $this->select()->fetchArray();
    }
    public function getActiveEngineConfig(string $scope = 'default'): ?array
    {
        $configs = $this->getConfigByScope($scope, true);
        if (empty($configs)) {
            if ($scope !== 'default') {
                return $this->getActiveEngineConfig('default');
            }
            return null;
        }
        return $configs[0] ?? null;
    }
    public function saveConfig(string $engineType, string $scope, array $configData, bool $isActive = true, int $priority = 0): bool
    {
        $this->clear();
        $existing = $this->where(self::schema_fields_ENGINE_TYPE, $engineType)
            ->where(self::schema_fields_SCOPE, $scope)
            ->find()
            ->fetch();
        if ($existing->getId()) {
            $existing->setData(self::schema_fields_CONFIG_DATA, json_encode($configData, JSON_UNESCAPED_UNICODE))
                ->setData(self::schema_fields_IS_ACTIVE, $isActive ? 1 : 0)
                ->setData(self::schema_fields_PRIORITY, $priority)
                ->save();
        } else {
            $this->setData(self::schema_fields_ENGINE_TYPE, $engineType)
                ->setData(self::schema_fields_SCOPE, $scope)
                ->setData(self::schema_fields_CONFIG_DATA, json_encode($configData, JSON_UNESCAPED_UNICODE))
                ->setData(self::schema_fields_IS_ACTIVE, $isActive ? 1 : 0)
                ->setData(self::schema_fields_PRIORITY, $priority)
                ->save();
        }
        return true;
    }
    public function getConfigData(): array
    {
        $configData = $this->getData(self::schema_fields_CONFIG_DATA);
        if (empty($configData)) return [];
        $decoded = json_decode($configData, true);
        return is_array($decoded) ? $decoded : [];
    }
    public function getAllScopes(): array
    {
        $this->clear();
        // PostgreSQL 下框架的 fields('DISTINCT xxx') 会把整段表达式当成字段名引用，导致
        // SQLSTATE[42703]: column "DISTINCT scope" does not exist。
        // 这里改用 GROUP BY scope 获取唯一作用域。
        $this->fields(self::schema_fields_SCOPE)
            ->group(self::schema_fields_SCOPE)
            ->order(self::schema_fields_SCOPE, 'ASC');
        $scopes = $this->select()->fetchArray();
        return array_values(array_unique(array_column($scopes, self::schema_fields_SCOPE)));
    }
}
