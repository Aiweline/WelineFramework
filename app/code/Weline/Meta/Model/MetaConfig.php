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
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/** MetaConfig 模型 - 存储主题配置信息 */
#[Table(comment: '主题配置表')]
#[Index(name: 'uk_identify_meta_identify_namespace_key_scope_locale', columns: ['identify_id', 'meta_identify', 'namespace', 'config_key', 'scope', 'locale'], type: 'UNIQUE')]
#[Index(name: 'uk_meta_namespace_key_scope_locale', columns: ['meta_id', 'namespace', 'config_key', 'scope', 'locale'], type: 'UNIQUE')]
#[Index(name: 'idx_identify_id', columns: ['identify_id'])]
#[Index(name: 'idx_meta_id', columns: ['meta_id'])]
#[Index(name: 'idx_meta_identify', columns: ['meta_identify'])]
#[Index(name: 'idx_namespace', columns: ['namespace'])]
#[Index(name: 'idx_config_key', columns: ['config_key'])]
#[Index(name: 'idx_scope', columns: ['scope'])]
#[Index(name: 'idx_locale', columns: ['locale'])]
#[Index(name: 'idx_identify_namespace', columns: ['identify_id', 'namespace'])]
#[Index(name: 'idx_identify_namespace_scope_locale', columns: ['identify_id', 'namespace', 'scope', 'locale'])]
#[Index(name: 'idx_meta_namespace', columns: ['meta_id', 'namespace'])]
#[Index(name: 'idx_meta_identify_namespace', columns: ['meta_identify', 'namespace'])]
#[Index(name: 'idx_meta_identify_lookup', columns: ['meta_identify', 'namespace', 'config_key', 'scope', 'locale'])]
#[Index(name: 'idx_namespace_meta_identify_scope', columns: ['namespace', 'meta_identify', 'scope', 'locale'])]
#[Index(name: 'idx_namespace_key', columns: ['namespace', 'config_key'])]
class MetaConfig extends AbstractModel
{

    public const schema_table = 'w_meta_config';
    public const schema_primary_key = 'config_id';
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '配置ID')]
    public const schema_fields_ID = 'config_id';
    #[Col('varchar', 255, comment: '实体标识ID')]
    public const schema_fields_IDENTIFY_ID = 'identify_id';
    #[Col('int', comment: 'Meta记录ID')]
    public const schema_fields_META_ID = 'meta_id';
    #[Col('varchar', 255, comment: 'Meta标识')]
    public const schema_fields_META_IDENTIFY = 'meta_identify';
    #[Col('varchar', 100, nullable: false, comment: '命名空间')]
    public const schema_fields_NAMESPACE = 'namespace';
    #[Col('varchar', 255, nullable: false, comment: '配置键')]
    public const schema_fields_CONFIG_KEY = 'config_key';
    #[Col('varchar', 255, nullable: false, comment: '配置值')]
    public const schema_fields_CONFIG_VALUE = 'config_value';
    #[Col('varchar', 100, default: 'default', comment: '作用域')]
    public const schema_fields_SCOPE = 'scope';
    #[Col('varchar', 20, comment: '语言代码')]
    public const schema_fields_LOCALE = 'locale';

    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['config_id'];

    /**
     * 索引排序键（用于提升查询效率）
     */
    public array $_index_sort_keys = ['identify_id', 'meta_id', 'meta_identify', 'namespace', 'config_key', 'scope', 'locale'];
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
            ->where(self::schema_fields_NAMESPACE, $namespace)
            ->where(self::schema_fields_CONFIG_KEY, $configKey)
            ->where(self::schema_fields_SCOPE, $scope);
        
        if (!$this->applyIdentityFilters($query, $identifyId, $metaId, $metaIdentify)) {
            // 如果都没有提供，返回 null
            return null;
        }
        
        // 先尝试获取指定语言的配置
        $query->where(self::schema_fields_LOCALE, $locale)
            ->find()
            ->fetch();
        
        if ($this->getId()) {
            return $this->getData(self::schema_fields_CONFIG_VALUE);
        }
        
        // 如果当前语言没有配置，回退到默认语言
        if ($locale !== $defaultLocale) {
            $query = $this->reset()
                ->where(self::schema_fields_NAMESPACE, $namespace)
                ->where(self::schema_fields_CONFIG_KEY, $configKey)
                ->where(self::schema_fields_SCOPE, $scope);
            
            $this->applyIdentityFilters($query, $identifyId, $metaId, $metaIdentify);
            
            $query->where(self::schema_fields_LOCALE, $defaultLocale)
                ->find()
                ->fetch();
            
            if ($this->getId()) {
                return $this->getData(self::schema_fields_CONFIG_VALUE);
            }
        }
        
        // 如果默认语言也没有配置，尝试获取 null 语言的配置（通用配置）
        $query = $this->reset()
            ->where(self::schema_fields_NAMESPACE, $namespace)
            ->where(self::schema_fields_CONFIG_KEY, $configKey)
            ->where(self::schema_fields_SCOPE, $scope);
        
        $this->applyIdentityFilters($query, $identifyId, $metaId, $metaIdentify);
        
        $query->where(self::schema_fields_LOCALE, null, 'IS NULL')
            ->find()
            ->fetch();
        
        if ($this->getId()) {
            return $this->getData(self::schema_fields_CONFIG_VALUE);
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
                ->where(\Weline\Meta\Model\Meta::schema_fields_META_IDENTIFY, $metaIdentifyBase)
                ->where(\Weline\Meta\Model\Meta::schema_fields_NAMESPACE, $namespacePrefix)
                ->find()
                ->fetch();
            
            if ($meta && $meta->getId()) {
                // 使用 meta_id 和 meta_identify 查询
                return $this->getConfig(null, $namespace, $configKey, 'default', $locale, null, $meta->getId(), $meta->getData(\Weline\Meta\Model\Meta::schema_fields_META_IDENTIFY));
            }
        } catch (\Exception $e) {
            // 如果查找 Meta 失败，继续使用 identify_id 方式
        }
        
        // 回退：获取当前激活的主题，使用 identify_id
        try {
            $themeContext = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Framework\Runtime\RuntimeProviderResolver::class,
            )->resolve(\Weline\Framework\Runtime\ThemeContextProviderInterface::class);
            $theme = $themeContext?->resolveTheme($area);
            
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
            ->where(self::schema_fields_NAMESPACE, $namespace)
            ->where(self::schema_fields_CONFIG_KEY, $configKey)
            ->where(self::schema_fields_SCOPE, $scope);
        
        // 处理 locale 为 null 的情况（需要使用 IS NULL）
        if ($locale === null) {
            $query->where(self::schema_fields_LOCALE, null, 'IS NULL');
        } else {
            $query->where(self::schema_fields_LOCALE, $locale);
        }
        
        if (!$this->applyIdentityFilters($query, $identifyId, $metaId, $metaIdentify)) {
            // 如果都没有提供，无法设置
            return $this;
        }
        
        // 先查找是否存在
        /** @var static $existing */
        $existing = $query->find()->fetch();
        
        if ($existing->getId()) {
            // 更新现有记录
            $existing->setData(self::schema_fields_CONFIG_VALUE, $configValue);
            
            // 如果提供了 meta_id 或 meta_identify，也更新这些字段
            if ($metaId !== null) {
                $existing->setData(self::schema_fields_META_ID, $metaId);
            }
            if ($metaIdentify !== null) {
                $existing->setData(self::schema_fields_META_IDENTIFY, $metaIdentify);
            }
            
            $existing->save();
            // 将更新后的数据同步到当前实例
            $this->setData($existing->getData());
        } else {
            // 插入新记录
            $this->reset();
            if ($identifyId !== null) {
                $this->setData(self::schema_fields_IDENTIFY_ID, (string)$identifyId);
            }
            if ($metaId !== null) {
                $this->setData(self::schema_fields_META_ID, $metaId);
            }
            if ($metaIdentify !== null) {
                $this->setData(self::schema_fields_META_IDENTIFY, $metaIdentify);
            }
            $this->setData(self::schema_fields_NAMESPACE, $namespace)
                 ->setData(self::schema_fields_CONFIG_KEY, $configKey)
                 ->setData(self::schema_fields_CONFIG_VALUE, $configValue)
                 ->setData(self::schema_fields_SCOPE, $scope)
                 ->setData(self::schema_fields_LOCALE, $locale);
            
            // 使用 forceCheck 确保唯一索引检查
            try {
                $this->forceCheck()->save();
            } catch (\Throwable $e) {
                // 如果因为唯一索引冲突而失败，尝试更新现有记录
                // 重新查询（可能在其他条件匹配的情况下）
                $retryQuery = $this->reset()
                    ->where(self::schema_fields_NAMESPACE, $namespace)
                    ->where(self::schema_fields_CONFIG_KEY, $configKey)
                    ->where(self::schema_fields_SCOPE, $scope);
                
                if ($locale === null) {
                    $retryQuery->where(self::schema_fields_LOCALE, null, 'IS NULL');
                } else {
                    $retryQuery->where(self::schema_fields_LOCALE, $locale);
                }
                
                $this->applyIdentityFilters($retryQuery, $identifyId, $metaId, $metaIdentify);
                
                $retryExisting = $retryQuery->find()->fetch();
                if ($retryExisting->getId()) {
                    // 找到了现有记录，更新它
                    $retryExisting->setData(self::schema_fields_CONFIG_VALUE, $configValue)
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
            ->where(self::schema_fields_NAMESPACE, $namespace)
            ->where(self::schema_fields_CONFIG_KEY, $configKey);
        
        $this->applyIdentityFilters($this, $identifyId, $metaId, $metaIdentify);
        
        if ($scope !== null) {
            $this->where(self::schema_fields_SCOPE, $scope);
        }
        
        if ($locale !== null) {
            $this->where(self::schema_fields_LOCALE, $locale);
        }
        
        $this->delete();
        
        return $this;
    }

    private function applyIdentityFilters(mixed $query, mixed $identifyId, ?int $metaId = null, ?string $metaIdentify = null): bool
    {
        $hasIdentity = false;
        if ($metaId !== null) {
            $query->where(self::schema_fields_META_ID, $metaId);
            $hasIdentity = true;
        }
        if ($metaIdentify !== null && trim($metaIdentify) !== '') {
            $query->where(self::schema_fields_META_IDENTIFY, $metaIdentify);
            $hasIdentity = true;
        }
        if ($identifyId !== null && trim((string)$identifyId) !== '') {
            $query->where(self::schema_fields_IDENTIFY_ID, (string)$identifyId);
            $hasIdentity = true;
        }

        return $hasIdentity;
    }
}
