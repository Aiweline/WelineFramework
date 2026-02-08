<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Meta\Model;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * MetaConfig 模型
 * 用于存储主题配置信息（theme_id + namespace + key -> value）
 */
class MetaConfig extends AbstractModel
{
    public const table = 'w_meta_config';
    
    const fields_ID = 'config_id';
    const fields_IDENTIFY_ID = 'identify_id';
    const fields_META_ID = 'meta_id';
    const fields_META_IDENTIFY = 'meta_identify';
    const fields_NAMESPACE = 'namespace';
    const fields_CONFIG_KEY = 'config_key';
    const fields_CONFIG_VALUE = 'config_value';
    const fields_SCOPE = 'scope';
    const fields_LOCALE = 'locale';

    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['config_id'];

    /**
     * 索引排序键（用于提升查询效率）
     */
    public array $_index_sort_keys = ['identify_id', 'meta_id', 'meta_identify', 'namespace', 'config_key', 'scope'];

    /**
     * 安装表结构
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('主题配置表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'primary key auto_increment',
                    '配置ID'
                )
                ->addColumn(
                    self::fields_IDENTIFY_ID,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'default null',
                    '实体标识ID（字符串或数字，可为空，标记来自哪个实体的元数据）'
                )
                ->addColumn(
                    self::fields_META_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'default null',
                    'Meta记录ID（指向Meta表的meta_id）'
                )
                ->addColumn(
                    self::fields_META_IDENTIFY,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'default null',
                    'Meta标识（来自Meta记录的meta_identify）'
                )
                ->addColumn(
                    self::fields_NAMESPACE,
                    TableInterface::column_type_VARCHAR,
                    100,
                    'not null',
                    '命名空间：theme.frontend, theme.backend等'
                )
                ->addColumn(
                    self::fields_CONFIG_KEY,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null',
                    '配置键：如 partials.header, layouts.account等'
                )
                ->addColumn(
                    self::fields_CONFIG_VALUE,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null',
                    '配置值：如 default, minimal等'
                )
                ->addColumn(
                    self::fields_SCOPE,
                    TableInterface::column_type_VARCHAR,
                    100,
                    'default "default"',
                    '作用域：default, homepage, store_1等'
                )
                ->addColumn(
                    self::fields_LOCALE,
                    TableInterface::column_type_VARCHAR,
                    20,
                    'default null',
                    '语言代码：zh_Hans_CN, en_US等，null表示默认语言'
                )
                // 唯一索引：防止重复配置（支持两种组合）
                ->addIndex(
                    TableInterface::index_type_UNIQUE,
                    'uk_identify_namespace_key_scope_locale',
                    [self::fields_IDENTIFY_ID, self::fields_NAMESPACE, self::fields_CONFIG_KEY, self::fields_SCOPE, self::fields_LOCALE],
                    '唯一索引：实体ID+命名空间+配置键+作用域+语言'
                )
                ->addIndex(
                    TableInterface::index_type_UNIQUE,
                    'uk_meta_namespace_key_scope_locale',
                    [self::fields_META_ID, self::fields_NAMESPACE, self::fields_CONFIG_KEY, self::fields_SCOPE, self::fields_LOCALE],
                    '唯一索引：MetaID+命名空间+配置键+作用域+语言'
                )
                // 普通索引：提升查询效率
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_identify_id',
                    self::fields_IDENTIFY_ID,
                    '实体ID索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_meta_id',
                    self::fields_META_ID,
                    'MetaID索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_meta_identify',
                    self::fields_META_IDENTIFY,
                    'Meta标识索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_namespace',
                    self::fields_NAMESPACE,
                    '命名空间索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_config_key',
                    self::fields_CONFIG_KEY,
                    '配置键索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_scope',
                    self::fields_SCOPE,
                    '作用域索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_locale',
                    self::fields_LOCALE,
                    '语言索引'
                )
                // 复合索引：提升常见查询组合的效率
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_identify_namespace',
                    [self::fields_IDENTIFY_ID, self::fields_NAMESPACE],
                    '复合索引：实体ID+命名空间'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_meta_namespace',
                    [self::fields_META_ID, self::fields_NAMESPACE],
                    '复合索引：MetaID+命名空间'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_meta_identify_namespace',
                    [self::fields_META_IDENTIFY, self::fields_NAMESPACE],
                    '复合索引：Meta标识+命名空间'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_namespace_key',
                    [self::fields_NAMESPACE, self::fields_CONFIG_KEY],
                    '复合索引：命名空间+配置键'
                )
                ->addAdditional('ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT=\'主题配置表\'')
                ->create();
        }
    }

    /**
     * 设置表结构（开发模式下每次都会执行）
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        // 在开发模式下，如果表已存在，先删除再重建（确保包含最新的字段）
        if (defined('DEV') && DEV && $setup->tableExist()) {
            $setup->dropTable();
        }
        $this->install($setup, $context);
    }

    /**
     * 获取配置值（支持语言回退）
     * 
     * @param int|string|null $identifyId 实体ID（主题ID或其他实体ID，可为null）
     * @param string $namespace 命名空间（如 theme.frontend）
     * @param string $configKey 配置键（如 partials.header）
     * @param string $scope 作用域，默认 'default'
     * @param string|null $locale 语言代码，如果为 null 则使用默认语言
     * @param string|null $defaultLocale 默认语言代码，如果为 null 则从 Cookie 获取
     * @param int|null $metaId Meta记录ID（可选）
     * @param string|null $metaIdentify Meta标识（可选）
     * @return string|null 配置值，如果不存在返回 null
     */
    public function getConfig($identifyId, string $namespace, string $configKey, string $scope = 'default', ?string $locale = null, ?string $defaultLocale = null, ?int $metaId = null, ?string $metaIdentify = null): ?string
    {
        // 如果没有指定语言，使用当前语言
        if ($locale === null) {
            $locale = \Weline\Framework\Http\Cookie::getLang() ?? 'zh_Hans_CN';
        }
        
        // 如果没有指定默认语言，使用框架默认语言
        if ($defaultLocale === null) {
            $defaultLocale = 'zh_Hans_CN';
        }
        
        // 构建查询条件
        $query = $this->reset()
            ->where(self::fields_NAMESPACE, $namespace)
            ->where(self::fields_CONFIG_KEY, $configKey)
            ->where(self::fields_SCOPE, $scope);
        
        // 优先使用 meta_id 或 meta_identify 查询
        if ($metaId !== null) {
            $query->where(self::fields_META_ID, $metaId);
        } elseif ($metaIdentify !== null) {
            $query->where(self::fields_META_IDENTIFY, $metaIdentify);
        } elseif ($identifyId !== null) {
            $query->where(self::fields_IDENTIFY_ID, (string)$identifyId);
        } else {
            // 如果都没有提供，返回 null
            return null;
        }
        
        // 先尝试获取指定语言的配置
        $query->where(self::fields_LOCALE, $locale)
            ->find()
            ->fetch();
        
        if ($this->getId()) {
            return $this->getData(self::fields_CONFIG_VALUE);
        }
        
        // 如果当前语言没有配置，回退到默认语言
        if ($locale !== $defaultLocale) {
            $query = $this->reset()
                ->where(self::fields_NAMESPACE, $namespace)
                ->where(self::fields_CONFIG_KEY, $configKey)
                ->where(self::fields_SCOPE, $scope);
            
            if ($metaId !== null) {
                $query->where(self::fields_META_ID, $metaId);
            } elseif ($metaIdentify !== null) {
                $query->where(self::fields_META_IDENTIFY, $metaIdentify);
            } else {
                $query->where(self::fields_IDENTIFY_ID, (string)$identifyId);
            }
            
            $query->where(self::fields_LOCALE, $defaultLocale)
                ->find()
                ->fetch();
            
            if ($this->getId()) {
                return $this->getData(self::fields_CONFIG_VALUE);
            }
        }
        
        // 如果默认语言也没有配置，尝试获取 null 语言的配置（通用配置）
        $query = $this->reset()
            ->where(self::fields_NAMESPACE, $namespace)
            ->where(self::fields_CONFIG_KEY, $configKey)
            ->where(self::fields_SCOPE, $scope);
        
        if ($metaId !== null) {
            $query->where(self::fields_META_ID, $metaId);
        } elseif ($metaIdentify !== null) {
            $query->where(self::fields_META_IDENTIFY, $metaIdentify);
        } else {
            $query->where(self::fields_IDENTIFY_ID, (string)$identifyId);
        }
        
        $query->where(self::fields_LOCALE, null, 'IS NULL')
            ->find()
            ->fetch();
        
        if ($this->getId()) {
            return $this->getData(self::fields_CONFIG_VALUE);
        }
        
        return null;
    }

    /**
     * 根据 identify 获取配置值（支持语言回退）
     * 优先通过 meta_identify 查找 Meta 记录
     * 
     * @param string $identify 配置标识（如 theme.frontend.partials.header）
     * @param string $field 字段名（如 value, file_path, config）
     * @param string|null $locale 语言代码，如果为 null 则使用当前语言
     * @return string|null 配置值，如果不存在返回 null
     */
    public function getConfigByIdentify(string $identify, string $field = 'value', ?string $locale = null): ?string
    {
        // 解析 identify：theme.frontend.partials.header
        // 需要提取：namespace (theme.frontend), config_key (partials.header)
        $parts = explode('.', $identify);
        if (count($parts) < 3) {
            return null;
        }
        
        // 第一部分是命名空间前缀（theme）
        // 第二部分是区域（frontend/backend）
        // 剩余部分是配置键
        $namespacePrefix = $parts[0]; // theme
        $area = $parts[1] ?? 'frontend'; // frontend 或 backend
        $configKey = implode('.', array_slice($parts, 2)); // partials.header
        
        $namespace = "{$namespacePrefix}.{$area}";
        
        // 优先通过 meta_identify 查找 Meta 记录
        try {
            /** @var \Weline\Meta\Model\Meta $metaModel */
            $metaModel = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Meta\Model\Meta::class);
            
            // 构建 meta_identify：theme.frontend.partials.header（去掉最后的 .value 等后缀）
            $metaIdentifyBase = $identify;
            if (str_ends_with($metaIdentifyBase, '.value') || str_ends_with($metaIdentifyBase, '.info') || str_ends_with($metaIdentifyBase, '.lang')) {
                $metaIdentifyBase = substr($metaIdentifyBase, 0, -strlen(strrchr($metaIdentifyBase, '.')));
            }
            
            // 尝试通过 meta_identify 查找 Meta 记录
            $meta = $metaModel->reset()
                ->where(\Weline\Meta\Model\Meta::fields_META_IDENTIFY, $metaIdentifyBase)
                ->where(\Weline\Meta\Model\Meta::fields_NAMESPACE, $namespacePrefix)
                ->find()
                ->fetch();
            
            if ($meta && $meta->getId()) {
                // 使用 meta_id 和 meta_identify 查询
                return $this->getConfig(null, $namespace, $configKey, 'default', $locale, null, $meta->getId(), $meta->getData(\Weline\Meta\Model\Meta::fields_META_IDENTIFY));
            }
        } catch (\Exception $e) {
            // 如果查找 Meta 失败，继续使用 identify_id 方式
        }
        
        // 回退：获取当前激活的主题，使用 identify_id
        try {
            /** @var \Weline\Theme\Model\WelineTheme $theme */
            $theme = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Theme\Model\WelineTheme::class);
            $theme = $theme->getActiveTheme();
            
            if ($theme && $theme->getId()) {
                return $this->getConfig($theme->getId(), $namespace, $configKey, 'default', $locale);
            }
        } catch (\Exception $e) {
            // 如果获取主题失败，返回 null
        }
        
        return null;
    }

    /**
     * 设置配置值
     * 
     * @param int|string|null $identifyId 实体ID（主题ID或其他实体ID，可为null）
     * @param string $namespace 命名空间
     * @param string $configKey 配置键
     * @param string $configValue 配置值
     * @param string $scope 作用域，默认 'default'
     * @param string|null $locale 语言代码，如果为 null 表示默认语言（通用配置）
     * @param int|null $metaId Meta记录ID（可选）
     * @param string|null $metaIdentify Meta标识（可选）
     * @return static
     */
    public function setConfig($identifyId, string $namespace, string $configKey, string $configValue, string $scope = 'default', ?string $locale = null, ?int $metaId = null, ?string $metaIdentify = null): static
    {
        // 构建查询条件
        $query = $this->reset()
            ->where(self::fields_NAMESPACE, $namespace)
            ->where(self::fields_CONFIG_KEY, $configKey)
            ->where(self::fields_SCOPE, $scope);
        
        // 处理 locale 为 null 的情况（需要使用 IS NULL）
        if ($locale === null) {
            $query->where(self::fields_LOCALE, null, 'IS NULL');
        } else {
            $query->where(self::fields_LOCALE, $locale);
        }
        
        // 优先使用 meta_id 或 meta_identify 查询
        if ($metaId !== null) {
            $query->where(self::fields_META_ID, $metaId);
        } elseif ($metaIdentify !== null) {
            $query->where(self::fields_META_IDENTIFY, $metaIdentify);
        } elseif ($identifyId !== null) {
            $query->where(self::fields_IDENTIFY_ID, (string)$identifyId);
        } else {
            // 如果都没有提供，无法设置
            return $this;
        }
        
        // 先查找是否存在
        /** @var static $existing */
        $existing = $query->find()->fetch();
        
        if ($existing->getId()) {
            // 更新现有记录
            $existing->setData(self::fields_CONFIG_VALUE, $configValue);
            
            // 如果提供了 meta_id 或 meta_identify，也更新这些字段
            if ($metaId !== null) {
                $existing->setData(self::fields_META_ID, $metaId);
            }
            if ($metaIdentify !== null) {
                $existing->setData(self::fields_META_IDENTIFY, $metaIdentify);
            }
            
            $existing->save();
            // 将更新后的数据同步到当前实例
            $this->setData($existing->getData());
        } else {
            // 插入新记录
            $this->reset();
            if ($identifyId !== null) {
                $this->setData(self::fields_IDENTIFY_ID, (string)$identifyId);
            }
            if ($metaId !== null) {
                $this->setData(self::fields_META_ID, $metaId);
            }
            if ($metaIdentify !== null) {
                $this->setData(self::fields_META_IDENTIFY, $metaIdentify);
            }
            $this->setData(self::fields_NAMESPACE, $namespace)
                 ->setData(self::fields_CONFIG_KEY, $configKey)
                 ->setData(self::fields_CONFIG_VALUE, $configValue)
                 ->setData(self::fields_SCOPE, $scope)
                 ->setData(self::fields_LOCALE, $locale);
            
            // 使用 forceCheck 确保唯一索引检查
            try {
                $this->forceCheck()->save();
            } catch (\Throwable $e) {
                // 如果因为唯一索引冲突而失败，尝试更新现有记录
                // 重新查询（可能在其他条件匹配的情况下）
                $retryQuery = $this->reset()
                    ->where(self::fields_NAMESPACE, $namespace)
                    ->where(self::fields_CONFIG_KEY, $configKey)
                    ->where(self::fields_SCOPE, $scope);
                
                if ($locale === null) {
                    $retryQuery->where(self::fields_LOCALE, null, 'IS NULL');
                } else {
                    $retryQuery->where(self::fields_LOCALE, $locale);
                }
                
                // 尝试使用不同的条件组合查找
                if ($metaId !== null) {
                    $retryQuery->where(self::fields_META_ID, $metaId);
                }
                if ($metaIdentify !== null) {
                    $retryQuery->where(self::fields_META_IDENTIFY, $metaIdentify);
                }
                if ($identifyId !== null) {
                    $retryQuery->where(self::fields_IDENTIFY_ID, (string)$identifyId);
                }
                
                $retryExisting = $retryQuery->find()->fetch();
                if ($retryExisting->getId()) {
                    // 找到了现有记录，更新它
                    $retryExisting->setData(self::fields_CONFIG_VALUE, $configValue)
                                  ->save();
                    $this->setData($retryExisting->getData());
                } else {
                    // 如果还是找不到，抛出异常
                    throw new \Exception(__('保存配置失败：无法插入新记录，也无法找到现有记录。错误：%{error}', [
                        'error' => $e->getMessage()
                    ]));
                }
            }
        }
        
        return $this;
    }

    /**
     * 删除配置
     * 
     * @param int|string|null $identifyId 实体ID（主题ID或其他实体ID，可为null）
     * @param string $namespace 命名空间
     * @param string $configKey 配置键
     * @param string|null $scope 作用域，如果为 null 则删除所有作用域的配置
     * @param string|null $locale 语言代码，如果为 null 则删除所有语言的配置
     * @param int|null $metaId Meta记录ID（可选）
     * @param string|null $metaIdentify Meta标识（可选）
     * @return static
     */
    public function deleteConfig($identifyId, string $namespace, string $configKey, ?string $scope = null, ?string $locale = null, ?int $metaId = null, ?string $metaIdentify = null): static
    {
        $this->reset()
            ->where(self::fields_NAMESPACE, $namespace)
            ->where(self::fields_CONFIG_KEY, $configKey);
        
        // 优先使用 meta_id 或 meta_identify 查询
        if ($metaId !== null) {
            $this->where(self::fields_META_ID, $metaId);
        } elseif ($metaIdentify !== null) {
            $this->where(self::fields_META_IDENTIFY, $metaIdentify);
        } elseif ($identifyId !== null) {
            $this->where(self::fields_IDENTIFY_ID, (string)$identifyId);
        }
        
        if ($scope !== null) {
            $this->where(self::fields_SCOPE, $scope);
        }
        
        if ($locale !== null) {
            $this->where(self::fields_LOCALE, $locale);
        }
        
        $this->delete();
        
        return $this;
    }
}
