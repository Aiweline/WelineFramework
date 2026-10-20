<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Meta\Helper;

use Weline\Framework\Manager\ObjectManager;
use Weline\Meta\Model\Meta;
use Weline\Meta\Model\MetaLocal;
use Weline\Meta\Service\MetaLocalGcService;
use Weline\Meta\Service\MetaLocalWordParser;

/**
 * Meta 翻译：只读写 w_meta_local（含 scope / version_id）。
 */
class MetaTranslation
{
    public static function getTranslatedValue(
        string $metaIdentify,
        string $configKey,
        ?string $locale = null,
        ?string $defaultValue = null,
        string $scope = MetaLocal::DEFAULT_SCOPE,
        int $versionId = MetaLocal::DEFAULT_VERSION_ID,
    ): string {
        $locale = self::resolveLocale($locale);
        $scope = self::normalizeScope($scope);
        $metaIdentify = trim($metaIdentify);
        if ($metaIdentify === '') {
            return $defaultValue ?? '';
        }

        $value = self::loadRow($metaIdentify, $locale, $configKey, $scope, $versionId);
        if ($value !== '') {
            return $value;
        }
        // fallback: same scope version 0, then default scope
        if ($versionId !== MetaLocal::DEFAULT_VERSION_ID) {
            $value = self::loadRow($metaIdentify, $locale, $configKey, $scope, MetaLocal::DEFAULT_VERSION_ID);
            if ($value !== '') {
                return $value;
            }
        }
        if ($scope !== MetaLocal::DEFAULT_SCOPE) {
            $value = self::loadRow($metaIdentify, $locale, $configKey, MetaLocal::DEFAULT_SCOPE, MetaLocal::DEFAULT_VERSION_ID);
            if ($value !== '') {
                return $value;
            }
        }

        return $defaultValue ?? '';
    }

    public static function getParamTranslation(string $metaIdentify, string $paramName, ?string $locale = null, ?string $defaultValue = null, string $scope = MetaLocal::DEFAULT_SCOPE, int $versionId = MetaLocal::DEFAULT_VERSION_ID): string
    {
        return self::getTranslatedValue($metaIdentify, 'param.' . $paramName, $locale, $defaultValue, $scope, $versionId);
    }

    public static function getNameTranslation(string $metaIdentify, ?string $locale = null, ?string $defaultValue = null, string $scope = MetaLocal::DEFAULT_SCOPE, int $versionId = MetaLocal::DEFAULT_VERSION_ID): string
    {
        return self::getTranslatedValue($metaIdentify, 'name', $locale, $defaultValue, $scope, $versionId);
    }

    public static function getDescriptionTranslation(string $metaIdentify, ?string $locale = null, ?string $defaultValue = null, string $scope = MetaLocal::DEFAULT_SCOPE, int $versionId = MetaLocal::DEFAULT_VERSION_ID): string
    {
        return self::getTranslatedValue($metaIdentify, 'description', $locale, $defaultValue, $scope, $versionId);
    }

    /**
     * Theme 兼容：metaKey = identify.configKey 路径（可含 @meta:: / |scope:）。
     */
    public static function getTranslatedValueWithScope(string $metaKey, string $scope = MetaLocal::DEFAULT_SCOPE, ?string $locale = null, ?string $defaultValue = null, int $versionId = MetaLocal::DEFAULT_VERSION_ID): string
    {
        $parsed = self::parseMetaKey($metaKey, $scope);
        if ($parsed === null) {
            return $defaultValue ?? '';
        }
        return self::getTranslatedValue(
            $parsed['meta_identify'],
            $parsed['config_key'],
            $locale,
            $defaultValue,
            $parsed['scope'],
            $versionId,
        );
    }

    /**
     * MetaLocal 依赖 w_meta 父行。合法写入路径可补最小 stub（非迁移专用逻辑）。
     */
    public static function ensureMetaIdentify(string $metaIdentify): ?int
    {
        $metaIdentify = trim($metaIdentify);
        if ($metaIdentify === '') {
            return null;
        }
        $existing = self::resolveMetaId($metaIdentify);
        if ($existing !== null) {
            return $existing;
        }

        $attrs = self::inferMetaStubAttributes($metaIdentify);
        try {
            /** @var Meta $metaModel */
            $metaModel = ObjectManager::make(Meta::class);
            $metaModel->setData(Meta::schema_fields_NAMESPACE, $attrs['namespace']);
            $metaModel->setData(Meta::schema_fields_META_TYPE, $attrs['meta_type']);
            $metaModel->setData(Meta::schema_fields_META_IDENTIFY, $metaIdentify);
            $metaModel->setData(Meta::schema_fields_AREA, $attrs['area']);
            $metaModel->setData(Meta::schema_fields_CATEGORY, $attrs['category']);
            $metaModel->setData(Meta::schema_fields_META_DATA, json_encode([
                'name' => $attrs['label'],
                'stub' => true,
                'source' => 'meta_local_ensure',
            ], JSON_UNESCAPED_UNICODE));
            $metaModel->forceCheck()->save();
            $id = (int)$metaModel->getId();
            return $id > 0 ? $id : null;
        } catch (\Throwable $e) {
            // 并发唯一键冲突：再读一次
            $again = self::resolveMetaId($metaIdentify);
            if ($again !== null) {
                return $again;
            }
            if (php_sapi_name() === 'cli') {
                echo '  [!] ensureMetaIdentify 失败: ' . $metaIdentify . ' - ' . $e->getMessage() . "\n";
            } else {
                w_log_error('ensureMetaIdentify 失败: ' . $metaIdentify . ' - ' . $e->getMessage());
            }
            return null;
        }
    }

    public static function setTranslatedValue(
        string $metaIdentify,
        string $configKey,
        string $locale,
        string $value,
        string $scope = MetaLocal::DEFAULT_SCOPE,
        int $versionId = MetaLocal::DEFAULT_VERSION_ID,
    ): bool {
        try {
            // 有 Meta 行则用；无则尝试轻量建壳（AppearanceTokenGroup 等）
            $metaId = self::resolveMetaId($metaIdentify) ?? self::ensureMetaIdentify($metaIdentify);
            if ($metaId === null) {
                return false;
            }
            $scope = self::normalizeScope($scope);
            $versionId = max(0, $versionId);

            /** @var MetaLocal $localModel */
            $localModel = ObjectManager::getInstance(MetaLocal::class);
            // version_id=0 时 Model::save 的 force_check 会因真值判断漏掉该列，
            // 进而走带 conflict 字段的 insert 把事务弄脏；这里用可移植 Query 先查再更/插。
            $existing = $localModel->reset()
                ->whereNaturalKey($metaIdentify, $locale, $configKey, $scope, $versionId)
                ->find()
                ->fetchArray();

            $query = $localModel->getQuery()->clearQuery();
            if (is_array($existing) && $existing !== []) {
                $query
                    ->where(MetaLocal::schema_fields_META_IDENTIFY, $metaIdentify)
                    ->where(MetaLocal::schema_fields_LOCALE_CODE, $locale)
                    ->where(MetaLocal::schema_fields_CONFIG_KEY, $configKey)
                    ->where(MetaLocal::schema_fields_SCOPE, $scope)
                    ->where(MetaLocal::schema_fields_VERSION_ID, $versionId)
                    ->update([
                        MetaLocal::schema_fields_CONFIG_VALUE => $value,
                        MetaLocal::schema_fields_META_ID => $metaId,
                    ])
                    ->fetch();
            } else {
                // 不传 conflict 字段：普通 INSERT（禁止方言 ON CONFLICT）
                $query->insert([
                    MetaLocal::schema_fields_META_ID => $metaId,
                    MetaLocal::schema_fields_META_IDENTIFY => $metaIdentify,
                    MetaLocal::schema_fields_LOCALE_CODE => $locale,
                    MetaLocal::schema_fields_CONFIG_KEY => $configKey,
                    MetaLocal::schema_fields_CONFIG_VALUE => $value,
                    MetaLocal::schema_fields_SCOPE => $scope,
                    MetaLocal::schema_fields_VERSION_ID => $versionId,
                ])->fetch();
            }
            return true;
        } catch (\Throwable $e) {
            if (php_sapi_name() === 'cli') {
                echo "  [!] MetaTranslation 保存失败: {$metaIdentify}.{$configKey} ({$locale}/{$scope}/v{$versionId}) - " . $e->getMessage() . "\n";
            } else {
                w_log_error("MetaTranslation 保存失败: {$metaIdentify}.{$configKey} ({$locale}/{$scope}/v{$versionId}) - " . $e->getMessage());
            }
            return false;
        }
    }

    public static function setParamTranslation(string $metaIdentify, string $paramName, string $locale, string $value, string $scope = MetaLocal::DEFAULT_SCOPE, int $versionId = MetaLocal::DEFAULT_VERSION_ID): bool
    {
        return self::setTranslatedValue($metaIdentify, 'param.' . $paramName, $locale, $value, $scope, $versionId);
    }

    public static function setNameTranslation(string $metaIdentify, string $locale, string $value, string $scope = MetaLocal::DEFAULT_SCOPE, int $versionId = MetaLocal::DEFAULT_VERSION_ID): bool
    {
        return self::setTranslatedValue($metaIdentify, 'name', $locale, $value, $scope, $versionId);
    }

    public static function setDescriptionTranslation(string $metaIdentify, string $locale, string $value, string $scope = MetaLocal::DEFAULT_SCOPE, int $versionId = MetaLocal::DEFAULT_VERSION_ID): bool
    {
        return self::setTranslatedValue($metaIdentify, 'description', $locale, $value, $scope, $versionId);
    }

    public static function setTranslatedValueWithScope(string $metaKey, string $value, string $scope = MetaLocal::DEFAULT_SCOPE, ?string $locale = null, int $versionId = MetaLocal::DEFAULT_VERSION_ID): bool
    {
        $locale = self::resolveLocale($locale);
        $parsed = self::parseMetaKey($metaKey, $scope);
        if ($parsed === null) {
            return false;
        }
        return self::setTranslatedValue(
            $parsed['meta_identify'],
            $parsed['config_key'],
            $locale,
            $value,
            $parsed['scope'],
            $versionId,
        );
    }

    /**
     * 有行则返回译文（可空串）；无行返回 null（用于 scope 归属链）。
     */
    public static function findTranslatedValueWithScope(
        string $metaKey,
        string $scope = MetaLocal::DEFAULT_SCOPE,
        ?string $locale = null,
        int $versionId = MetaLocal::DEFAULT_VERSION_ID,
    ): ?string {
        $locale = self::resolveLocale($locale);
        $parsed = self::parseMetaKey($metaKey, $scope);
        if ($parsed === null) {
            return null;
        }
        /** @var MetaLocal $localModel */
        $localModel = ObjectManager::getInstance(MetaLocal::class);
        $localModel->reset()
            ->whereNaturalKey(
                $parsed['meta_identify'],
                $locale,
                $parsed['config_key'],
                $parsed['scope'],
                max(0, $versionId),
            )
            ->find()
            ->fetch();
        if (!$localModel->getMetaIdentify()) {
            return null;
        }

        return (string)($localModel->getConfigValue() ?? '');
    }

    public static function deleteTranslatedValueWithScope(
        string $metaKey,
        string $scope = MetaLocal::DEFAULT_SCOPE,
        ?string $locale = null,
        int $versionId = MetaLocal::DEFAULT_VERSION_ID,
    ): bool {
        $locale = self::resolveLocale($locale);
        $parsed = self::parseMetaKey($metaKey, $scope);
        if ($parsed === null) {
            return false;
        }
        try {
            /** @var MetaLocal $localModel */
            $localModel = ObjectManager::getInstance(MetaLocal::class);
            $localModel->reset()
                ->whereNaturalKey(
                    $parsed['meta_identify'],
                    $locale,
                    $parsed['config_key'],
                    self::normalizeScope($parsed['scope']),
                    max(0, $versionId),
                )
                ->find()
                ->fetch();
            if (!$localModel->getMetaIdentify()) {
                return true;
            }
            $localModel->delete();
            return true;
        } catch (\Throwable $e) {
            if (php_sapi_name() === 'cli') {
                echo '  [!] MetaTranslation 删除失败: ' . $e->getMessage() . "\n";
            } else {
                w_log_error('MetaTranslation 删除失败: ' . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * 按 meta_identify 前缀列出 MetaLocal 行，并还原为历史 @meta:: word 形状。
     *
     * @return list<array{word:string,locale_code:string,translate:string,scope:string,meta_identify:string,config_key:string}>
     */
    public static function listByIdentifyPrefix(string $identifyPrefix, ?string $locale = null, ?string $scope = null): array
    {
        $identifyPrefix = trim($identifyPrefix);
        if ($identifyPrefix === '') {
            return [];
        }
        if (str_starts_with($identifyPrefix, MetaLocalWordParser::META_PREFIX)) {
            $identifyPrefix = substr($identifyPrefix, strlen(MetaLocalWordParser::META_PREFIX));
        }
        $identifyPrefix = rtrim($identifyPrefix, '.');

        /** @var MetaLocal $localModel */
        $localModel = ObjectManager::getInstance(MetaLocal::class);
        $query = $localModel->reset()
            ->where(MetaLocal::schema_fields_META_IDENTIFY, $identifyPrefix . '%', 'LIKE');
        if ($locale !== null && $locale !== '') {
            $query->where(MetaLocal::schema_fields_LOCALE_CODE, $locale);
        }
        if ($scope !== null && $scope !== '') {
            $query->where(MetaLocal::schema_fields_SCOPE, self::normalizeScope($scope));
        }
        $records = $query->select()->fetchArray();
        $out = [];
        foreach ($records as $record) {
            $metaIdentify = (string)($record[MetaLocal::schema_fields_META_IDENTIFY] ?? '');
            $configKey = (string)($record[MetaLocal::schema_fields_CONFIG_KEY] ?? '');
            $rowScope = (string)($record[MetaLocal::schema_fields_SCOPE] ?? MetaLocal::DEFAULT_SCOPE);
            $localeCode = (string)($record[MetaLocal::schema_fields_LOCALE_CODE] ?? '');
            if ($metaIdentify === '' || $configKey === '' || $localeCode === '') {
                continue;
            }
            $out[] = [
                'word' => MetaLocalWordParser::toDictionaryWord($metaIdentify, $configKey, $rowScope),
                'locale_code' => $localeCode,
                'translate' => (string)($record[MetaLocal::schema_fields_CONFIG_VALUE] ?? ''),
                'scope' => $rowScope !== '' ? $rowScope : MetaLocal::DEFAULT_SCOPE,
                'meta_identify' => $metaIdentify,
                'config_key' => $configKey,
            ];
        }

        return $out;
    }

    public static function purgeByVersionId(int $versionId): int
    {
        /** @var MetaLocalGcService $gc */
        $gc = ObjectManager::getInstance(MetaLocalGcService::class);
        return $gc->purgeByVersionId($versionId);
    }

    public static function purgeByScopePrefix(string $prefix): int
    {
        /** @var MetaLocalGcService $gc */
        $gc = ObjectManager::getInstance(MetaLocalGcService::class);
        return $gc->purgeByScopePrefix($prefix);
    }

    public static function purgeByExactScope(string $scope): int
    {
        /** @var MetaLocalGcService $gc */
        $gc = ObjectManager::getInstance(MetaLocalGcService::class);
        return $gc->purgeByExactScope($scope);
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function getAllTranslations(string $metaIdentify, ?string $scope = null, ?int $versionId = null): array
    {
        $metaIdentify = trim($metaIdentify);
        if ($metaIdentify === '') {
            return [];
        }

        /** @var MetaLocal $localModel */
        $localModel = ObjectManager::getInstance(MetaLocal::class);
        $query = $localModel->reset()->where(MetaLocal::schema_fields_META_IDENTIFY, $metaIdentify);
        if ($scope !== null) {
            $query->where(MetaLocal::schema_fields_SCOPE, self::normalizeScope($scope));
        }
        if ($versionId !== null) {
            $query->where(MetaLocal::schema_fields_VERSION_ID, max(0, $versionId));
        }
        $records = $query->select()->fetchArray();

        $translations = [];
        foreach ($records as $record) {
            $localeCode = (string)$record[MetaLocal::schema_fields_LOCALE_CODE];
            $configKey = (string)$record[MetaLocal::schema_fields_CONFIG_KEY];
            $configValue = (string)($record[MetaLocal::schema_fields_CONFIG_VALUE] ?? '');
            $rowScope = (string)($record[MetaLocal::schema_fields_SCOPE] ?? MetaLocal::DEFAULT_SCOPE);
            $rowVersion = (int)($record[MetaLocal::schema_fields_VERSION_ID] ?? 0);
            $bucket = $localeCode . '|' . $rowScope . '|v' . $rowVersion;
            if (!isset($translations[$bucket])) {
                $translations[$bucket] = [];
            }
            $translations[$bucket][$configKey] = $configValue;
        }

        return $translations;
    }

    /**
     * @return array{meta_identify:string,config_key:string,scope:string}|null
     */
    private static function parseMetaKey(string $metaKey, string $fallbackScope): ?array
    {
        $metaKey = trim($metaKey);
        if ($metaKey === '') {
            return null;
        }
        if (str_starts_with($metaKey, MetaLocalWordParser::META_PREFIX) || str_contains($metaKey, '|scope:')) {
            $word = str_starts_with($metaKey, MetaLocalWordParser::META_PREFIX)
                ? $metaKey
                : MetaLocalWordParser::META_PREFIX . $metaKey;
            $parsed = MetaLocalWordParser::parseDictionaryWord($word);
            if ($parsed === null) {
                return null;
            }
            return [
                'meta_identify' => $parsed['meta_identify'],
                'config_key' => $parsed['config_key'],
                'scope' => $parsed['scope'] !== 'default' ? $parsed['scope'] : self::normalizeScope($fallbackScope),
            ];
        }
        $path = MetaLocalWordParser::parsePath($metaKey);
        if ($path === null) {
            return null;
        }
        return [
            'meta_identify' => $path['meta_identify'],
            'config_key' => $path['config_key'],
            'scope' => self::normalizeScope($fallbackScope),
        ];
    }

    private static function loadRow(
        string $metaIdentify,
        string $locale,
        string $configKey,
        string $scope,
        int $versionId,
    ): string {
        /** @var MetaLocal $localModel */
        $localModel = ObjectManager::getInstance(MetaLocal::class);
        $localModel->reset()
            ->whereNaturalKey($metaIdentify, $locale, $configKey, $scope, $versionId)
            ->find()
            ->fetch();
        if (!$localModel->getMetaIdentify()) {
            return '';
        }
        return trim((string)($localModel->getConfigValue() ?? ''));
    }

    private static function resolveMetaId(string $metaIdentify): ?int
    {
        $metaIdentify = trim($metaIdentify);
        if ($metaIdentify === '') {
            return null;
        }
        /** @var Meta $metaModel */
        $metaModel = ObjectManager::getInstance(Meta::class);
        $meta = $metaModel->reset()
            ->where(Meta::schema_fields_META_IDENTIFY, $metaIdentify)
            ->find()
            ->fetch();
        if (!$meta->getId()) {
            return null;
        }
        return (int)$meta->getId();
    }

    /**
     * @return array{namespace:string,meta_type:string,area:?string,category:?string,label:string}
     */
    private static function inferMetaStubAttributes(string $metaIdentify): array
    {
        $parts = explode('.', $metaIdentify);
        $namespace = $parts[0] !== '' ? $parts[0] : 'meta';
        $area = null;
        $metaType = 'local';
        $category = null;
        if ($namespace === 'theme' && isset($parts[1], $parts[2])) {
            $area = $parts[1];
            $metaType = $parts[2];
            $category = $parts[3] ?? null;
        } elseif (count($parts) >= 2) {
            $metaType = $parts[1];
            $category = $parts[2] ?? null;
        }
        $label = $parts[array_key_last($parts)] ?? $metaIdentify;

        return [
            'namespace' => $namespace,
            'meta_type' => $metaType,
            'area' => $area,
            'category' => $category,
            'label' => (string)$label,
        ];
    }

    private static function resolveLocale(?string $locale): string
    {
        if ($locale === null || $locale === '') {
            return \Weline\Framework\Env\WelineEnv::getLangLocal() ?? 'zh_Hans_CN';
        }
        return $locale;
    }

    private static function normalizeScope(string $scope): string
    {
        $scope = trim($scope);
        return $scope !== '' ? $scope : MetaLocal::DEFAULT_SCOPE;
    }
}
