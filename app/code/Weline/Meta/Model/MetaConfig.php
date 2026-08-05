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
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Meta\Api\Data\MetaConfigIdentity;
use Weline\Meta\Api\Data\MetaConfigScopeSearch;
use Weline\Meta\Api\Data\MetaConfigSearch;
use Weline\Meta\Api\Data\MetaConfigWrite;
use Weline\Meta\Api\MetaConfigRepositoryInterface;

/** MetaConfig 模型 - 存储主题配置信息 */
#[Table(comment: '主题配置表')]
#[Index(name: 'uk_w_meta_config_identity_fingerprint', columns: ['identity_fingerprint'], type: 'UNIQUE')]
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
    public const IDENTITY_FINGERPRINT_UNIQUE_INDEX = 'uk_w_meta_config_identity_fingerprint';
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
    #[Col('varchar', 64, nullable: true, comment: '七字段身份SHA-256指纹')]
    public const schema_fields_IDENTITY_FINGERPRINT = 'identity_fingerprint';

    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['config_id'];

    /**
     * 索引排序键（用于提升查询效率）
     */
    public array $_index_sort_keys = [
        'identify_id',
        'meta_id',
        'meta_identify',
        'namespace',
        'config_key',
        'scope',
        'locale',
        'identity_fingerprint',
    ];

    public function save_before(): void
    {
        parent::save_before();

        $configId = $this->getData(self::schema_fields_ID);
        $hasConfigId = $configId !== null && $configId !== '';
        if ($this->hasData(self::schema_fields_CONFIG_VALUE)) {
            $value = $this->getData(self::schema_fields_CONFIG_VALUE);
            if (!is_string($value)) {
                throw new \InvalidArgumentException('Meta config value must be a string.');
            }
            MetaConfigIdentity::assertValue($value);
        } elseif (!$hasConfigId) {
            throw new \InvalidArgumentException('Meta config insert requires a value.');
        }

        $identityFields = self::identityFields();
        $presentIdentityFields = array_values(array_filter(
            $identityFields,
            fn(string $field): bool => $this->hasData($field),
        ));
        if ($hasConfigId && $presentIdentityFields === []) {
            if ($this->hasData(self::schema_fields_IDENTITY_FINGERPRINT)) {
                throw new \LogicException('MetaConfig partial save cannot set identity_fingerprint without identity fields.');
            }
            $row = $this->readStoredRow($configId);
            $identity = $this->identityFromRow($row);
            $storedFingerprint = $this->rowValue($row, self::schema_fields_IDENTITY_FINGERPRINT);
            if (!is_string($storedFingerprint)
                || strlen($storedFingerprint) !== 64
                || !hash_equals($identity->fingerprint(), $storedFingerprint)) {
                throw new \RuntimeException(__('MetaConfig 部分更新要求已完成且有效的身份指纹，config_id=%{1}', [$configId]));
            }
            // A value-only update must not copy the identity snapshot back into
            // model data.  That would overwrite a concurrent identity change.
            return;
        }
        if ($hasConfigId && count($presentIdentityFields) !== count($identityFields)) {
            throw new \LogicException('MetaConfig identity updates must provide all seven identity fields.');
        }

        if (!$this->hasData(self::schema_fields_SCOPE)) {
            $this->setData(self::schema_fields_SCOPE, 'default');
        }
        $identity = $this->identityFromCurrentData();
        $this->setData(
            self::schema_fields_IDENTITY_FINGERPRINT,
            $identity->fingerprint(),
        );
    }

    /** @return list<string> */
    private static function identityFields(): array
    {
        return [
            self::schema_fields_NAMESPACE,
            self::schema_fields_CONFIG_KEY,
            self::schema_fields_SCOPE,
            self::schema_fields_LOCALE,
            self::schema_fields_IDENTIFY_ID,
            self::schema_fields_META_ID,
            self::schema_fields_META_IDENTIFY,
        ];
    }

    private function readStoredRow(mixed $configId): mixed
    {
        $rows = $this->newQuery()
            ->where(self::schema_fields_ID, $configId)
            ->select()
            ->fetchArray();
        $row = is_array($rows) ? ($rows[0] ?? null) : null;
        if (!is_array($row) && !(is_object($row) && method_exists($row, 'getData'))) {
            throw new \RuntimeException(__('MetaConfig 部分更新无法读取原身份，config_id=%{1}', [$configId]));
        }
        return $row;
    }

    private function identityFromCurrentData(): MetaConfigIdentity
    {
        return $this->buildIdentity(fn(string $field): mixed => $this->getData($field));
    }

    private function identityFromRow(mixed $row): MetaConfigIdentity
    {
        return $this->buildIdentity(fn(string $field): mixed => $this->rowValue($row, $field));
    }

    private function buildIdentity(callable $value): MetaConfigIdentity
    {
        return new MetaConfigIdentity(
            namespace: $this->requiredIdentityString($value(self::schema_fields_NAMESPACE), self::schema_fields_NAMESPACE),
            configKey: $this->requiredIdentityString($value(self::schema_fields_CONFIG_KEY), self::schema_fields_CONFIG_KEY),
            scope: $this->requiredIdentityString($value(self::schema_fields_SCOPE), self::schema_fields_SCOPE),
            locale: $this->optionalIdentityString($value(self::schema_fields_LOCALE), self::schema_fields_LOCALE),
            identifyId: $this->optionalIdentityString($value(self::schema_fields_IDENTIFY_ID), self::schema_fields_IDENTIFY_ID),
            metaId: $this->identityMetaId($value(self::schema_fields_META_ID)),
            metaIdentify: $this->optionalIdentityString($value(self::schema_fields_META_IDENTIFY), self::schema_fields_META_IDENTIFY),
        );
    }

    private function requiredIdentityString(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException("MetaConfig {$field} must be a string.");
        }
        return $value;
    }

    private function optionalIdentityString(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException("MetaConfig {$field} must be a string or NULL.");
        }
        return $value;
    }

    private function identityMetaId(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value) && $value > 0 && $value <= MetaConfigIdentity::META_ID_MAX) {
            return $value;
        }
        if (is_string($value)
            && ctype_digit($value)
            && (int)$value > 0
            && (int)$value <= MetaConfigIdentity::META_ID_MAX) {
            return (int)$value;
        }
        throw new \InvalidArgumentException('MetaConfig meta_id must be NULL or fit a positive signed 32-bit integer.');
    }

    private function rowValue(mixed $row, string $field, mixed $default = null): mixed
    {
        if (is_array($row)) {
            return array_key_exists($field, $row) ? $row[$field] : $default;
        }
        if (is_object($row) && method_exists($row, 'getData')) {
            return $row->getData($field);
        }
        return $default;
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

        $identifyId = $identifyId === null ? null : (string)$identifyId;
        if (!$this->hasRequestedOwner($identifyId, $metaId, $metaIdentify)) {
            return null;
        }

        /** @var MetaConfigRepositoryInterface $repository */
        $repository = ObjectManager::getInstance(MetaConfigRepositoryInterface::class);
        $locales = [];
        foreach ([$locale, $defaultLocale, null] as $candidateLocale) {
            $key = $candidateLocale === null ? 'null' : 'string:' . $candidateLocale;
            if (!array_key_exists($key, $locales)) {
                $locales[$key] = $candidateLocale;
            }
        }
        foreach ($locales as $candidateLocale) {
            $records = $repository->search(new MetaConfigSearch(
                namespace: $namespace,
                scope: $scope,
                configKey: $configKey,
                locale: $candidateLocale,
                identifyId: $identifyId,
                metaId: $metaId,
                metaIdentify: $metaIdentify,
            ));
            if (count($records) > 1) {
                throw new \RuntimeException(__('MetaConfig 可选 owner 身份存在歧义，config_id=%{1}', [
                    implode(',', array_map(static fn($record): int => $record->id, $records)),
                ]));
            }
            if (isset($records[0])) {
                return $records[0]->value;
            }
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
        $identity = new MetaConfigIdentity(
            namespace: $namespace,
            configKey: $configKey,
            scope: $scope,
            locale: $locale,
            identifyId: $identifyId === null ? null : (string)$identifyId,
            metaId: $metaId,
            metaIdentify: $metaIdentify,
        );
        /** @var MetaConfigRepositoryInterface $repository */
        $repository = ObjectManager::getInstance(MetaConfigRepositoryInterface::class);
        $record = $repository->upsert(new MetaConfigWrite($identity, $configValue));
        $storedIdentity = new MetaConfigIdentity(
            namespace: $record->namespace,
            configKey: $record->configKey,
            scope: $record->scope,
            locale: $record->locale,
            identifyId: $record->identifyId,
            metaId: $record->metaId,
            metaIdentify: $record->metaIdentify,
        );
        $this->setData([
            self::schema_fields_ID => $record->id,
            self::schema_fields_IDENTIFY_ID => $record->identifyId,
            self::schema_fields_META_ID => $record->metaId,
            self::schema_fields_META_IDENTIFY => $record->metaIdentify,
            self::schema_fields_NAMESPACE => $record->namespace,
            self::schema_fields_CONFIG_KEY => $record->configKey,
            self::schema_fields_CONFIG_VALUE => $record->value,
            self::schema_fields_SCOPE => $record->scope,
            self::schema_fields_LOCALE => $record->locale,
            self::schema_fields_IDENTITY_FINGERPRINT => $storedIdentity->fingerprint(),
        ]);

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
        $identifyId = $identifyId === null ? null : (string)$identifyId;
        if (!$this->hasRequestedOwner($identifyId, $metaId, $metaIdentify)) {
            return $this;
        }

        /** @var MetaConfigRepositoryInterface $repository */
        $repository = ObjectManager::getInstance(MetaConfigRepositoryInterface::class);
        /** @var WriteIntentTransactionCoordinatorInterface $transactions */
        $transactions = ObjectManager::getInstance(WriteIntentTransactionCoordinatorInterface::class);
        $transactions->runWrite($this->getConnection(), function () use (
            $repository,
            $identifyId,
            $namespace,
            $configKey,
            $scope,
            $locale,
            $metaId,
            $metaIdentify,
        ): void {
            $scopes = $scope === null
                ? $repository->listScopes(new MetaConfigScopeSearch(
                    namespace: $namespace,
                    identifyId: $identifyId,
                    metaId: $metaId,
                    metaIdentify: $metaIdentify,
                ))
                : [$scope];

            foreach ($scopes as $exactScope) {
                $records = $repository->search(new MetaConfigSearch(
                    namespace: $namespace,
                    scope: $exactScope,
                    configKey: $configKey,
                    locale: $locale,
                    allLocales: $locale === null,
                    identifyId: $identifyId,
                    metaId: $metaId,
                    metaIdentify: $metaIdentify,
                ));
                foreach ($records as $record) {
                    $targetIdentity = new MetaConfigIdentity(
                        namespace: $record->namespace,
                        configKey: $record->configKey,
                        scope: $record->scope,
                        locale: $record->locale,
                        identifyId: $record->identifyId,
                        metaId: $record->metaId,
                        metaIdentify: $record->metaIdentify,
                    );
                    $row = $this->readStoredRow($record->id);
                    $storedIdentity = $this->identityFromRow($row);
                    $storedFingerprint = $this->rowValue($row, self::schema_fields_IDENTITY_FINGERPRINT);
                    if (!$this->identitiesEqual($storedIdentity, $targetIdentity)
                        || !is_string($storedFingerprint)
                        || !hash_equals($targetIdentity->fingerprint(), $storedFingerprint)) {
                        throw new \RuntimeException(__('MetaConfig 兼容删除的事务连接与精确身份不匹配，config_id=%{1}', [
                            $record->id,
                        ]));
                    }

                    // Every mutation stays on this model's transaction
                    // connection. Repository reads may be wired through an
                    // equivalent wrapper, but can never independently commit
                    // one item from this legacy bulk-delete operation.
                    $this->newQuery()
                        ->where(self::schema_fields_ID, $record->id)
                        ->where(self::schema_fields_IDENTITY_FINGERPRINT, $storedFingerprint)
                        ->delete()
                        ->fetch();
                    $remaining = $this->newQuery()
                        ->where(self::schema_fields_ID, $record->id)
                        ->select()
                        ->fetchArray();
                    if (is_array($remaining) && $remaining === []) {
                        continue;
                    }
                    throw new \RuntimeException(__('MetaConfig 兼容删除未能删除精确记录，config_id=%{1}', [
                        $record->id,
                    ]));
                }
            }
        });
        \Weline\Meta\Helper\MetaData::clearCache();
        $this->reset();

        return $this;
    }

    private function hasRequestedOwner(?string $identifyId, ?int $metaId, ?string $metaIdentify): bool
    {
        return ($identifyId !== null && trim($identifyId) !== '')
            || $metaId !== null
            || ($metaIdentify !== null && trim($metaIdentify) !== '');
    }

    private function identitiesEqual(MetaConfigIdentity $left, MetaConfigIdentity $right): bool
    {
        return $left->namespace === $right->namespace
            && $left->configKey === $right->configKey
            && $left->scope === $right->scope
            && $left->locale === $right->locale
            && $left->identifyId === $right->identifyId
            && $left->metaId === $right->metaId
            && $left->metaIdentify === $right->metaIdentify;
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
