<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Service;

use Weline\SystemConfig\Model\SystemConfig;

/**
 * TASK-P1C-002：上级 lock → 下级弱覆盖 suppressed；解锁不复活；显式 restore/discard。
 *
 * 存储：行 metadata.suppressed_by_lock_version；父行 metadata.active_lock_version。
 * 不用 is_active 表达 suppress。
 */
final class SystemConfigLockService
{
    public const META_SUPPRESSED_BY = 'suppressed_by_lock_version';
    public const META_SUPPRESSED_AT = 'suppressed_at';
    public const META_SUPPRESSED_FROM = 'suppressed_from_scope';
    public const META_ACTIVE_LOCK = 'active_lock_version';
    public const META_LOCKED_AT = 'locked_at';

    public const OP_LOCK = 'lock';
    public const OP_UNLOCK = 'unlock';
    public const OP_RESTORE = 'restore_suppressed';
    public const OP_DISCARD = 'discard_suppressed';

    public function __construct(
        private readonly SystemConfig $config,
    ) {
    }

    /**
     * 判断 candidate 是否为 parent 的下级覆盖层（同 website 树内）。
     */
    public static function isDescendantScope(string $parent, string $candidate): bool
    {
        $parent = \strtolower(\trim($parent));
        $candidate = \strtolower(\trim($candidate));
        if ($candidate === '' || $candidate === $parent) {
            return false;
        }
        if ($parent === SystemConfig::SCOPE_GLOBAL || $parent === '') {
            return $candidate !== SystemConfig::SCOPE_GLOBAL;
        }

        [$pw, $ps, $pc] = \explode('.', $parent) + ['default', 'default', 'default'];
        [$cw, $cs, $cc] = \explode('.', $candidate) + ['default', 'default', 'default'];
        if ($cw !== $pw) {
            return false;
        }

        $parentIsWebsite = ($ps === SystemConfigScopeResolver::WEBSITE_DEFAULT_SENTINEL && $pc === 'default')
            || ($ps === 'default' && $pc === 'default');
        if ($parentIsWebsite) {
            $candidateIsWebsiteLevel = ($cs === SystemConfigScopeResolver::WEBSITE_DEFAULT_SENTINEL && $cc === 'default')
                || ($cs === 'default' && $cc === 'default');

            return !$candidateIsWebsiteLevel;
        }

        // store → channel only
        if ($pc === 'default') {
            return $cs === $ps && $cc !== 'default';
        }

        return false;
    }

    /**
     * @param array<string, mixed>|null $row
     */
    public static function isRowSuppressed(?array $row): bool
    {
        if ($row === null) {
            return false;
        }
        $meta = $row[SystemConfig::schema_fields_METADATA] ?? null;
        if (\is_string($meta)) {
            try {
                $decoded = \json_decode($meta, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $decoded = [];
            }
            $meta = $decoded;
        }
        if (!\is_array($meta)) {
            return false;
        }

        return isset($meta[self::META_SUPPRESSED_BY]) && (int)$meta[self::META_SUPPRESSED_BY] > 0;
    }

    /**
     * @return array{parent_scope:string,children:list<array<string,mixed>>,count:int}
     */
    public function previewLock(
        string $module,
        string $area,
        string $key,
        ?string $scope = null,
        ?string $locale = null,
    ): array {
        $scope = $this->config->normalizeScope($scope);
        $locale = $this->config->normalizeLocale($locale);
        $children = $this->listDescendantOverrides($module, $area, $key, $scope, $locale);

        return [
            'parent_scope' => $scope,
            'key' => $key,
            'module' => $module,
            'area' => $area,
            'locale' => $locale,
            'children' => $children,
            'count' => \count($children),
        ];
    }

    /**
     * @param array<string, mixed> $options base_versions / actor_* / reason / force
     * @return array<string, mixed>
     */
    public function lockScope(
        string $module,
        string $area,
        string $key,
        ?string $scope = null,
        ?string $locale = null,
        array $options = [],
    ): array {
        $scope = $this->config->normalizeScope($scope);
        $locale = $this->config->normalizeLocale($locale);
        $preview = $this->previewLock($module, $area, $key, $scope, $locale);
        $force = !empty($options['force']);

        // 父行必须存在：要求先有父层显式值
        $parentRow = $this->config->getScopedConfigRow($key, $module, $area, $scope, $locale);
        if ($parentRow === null) {
            return [
                'success' => false,
                'status' => 'parent_missing',
                'error' => 'system_config_lock_requires_parent_row',
            ];
        }

        $baseVersions = \is_array($options['base_versions'] ?? null) ? $options['base_versions'] : [];
        if (!$force && isset($baseVersions[$key])) {
            $current = (int)($parentRow[SystemConfig::schema_fields_VERSION] ?? 0);
            if ($current !== (int)$baseVersions[$key]) {
                return [
                    'success' => false,
                    'status' => 'conflict',
                    'conflicts' => [[
                        'key' => $key,
                        'scope' => $scope,
                        'expected_version' => (int)$baseVersions[$key],
                        'current_version' => $current,
                    ]],
                ];
            }
        }

        foreach ($preview['children'] as $child) {
            $ck = (string)$child['key'];
            $childScope = (string)$child['scope'];
            $expected = $baseVersions[$ck . '@' . $childScope] ?? $baseVersions[$ck] ?? null;
            if (!$force && $expected !== null && (int)$child['version'] !== (int)$expected) {
                return [
                    'success' => false,
                    'status' => 'conflict',
                    'conflicts' => [[
                        'key' => $ck,
                        'scope' => $childScope,
                        'expected_version' => (int)$expected,
                        'current_version' => (int)$child['version'],
                    ]],
                ];
            }
        }

        // 先记 lock 版本占位 ID：用 save 批次写父 metadata，再 suppress 子行
        $lockBatch = $this->config->saveScopeConfig(
            $module,
            $area,
            [$key => $this->rawValueFromRow($parentRow)],
            $scope,
            $locale,
            [
                'operation' => self::OP_LOCK,
                'base_versions' => [$key => (int)($parentRow[SystemConfig::schema_fields_VERSION] ?? 0)],
                'field_metadata' => [
                    $key => $this->mergeMetadata($parentRow, [
                        self::META_ACTIVE_LOCK => 0, // 占位，稍后回填 version_id
                        self::META_LOCKED_AT => \gmdate('c'),
                    ]),
                ],
                'value_types' => [$key => (string)($parentRow[SystemConfig::schema_fields_VALUE_TYPE] ?? 'string')],
                'is_sensitive_values' => [$key => (int)($parentRow[SystemConfig::schema_fields_IS_SENSITIVE] ?? 0)],
                'actor_id' => (string)($options['actor_id'] ?? ''),
                'actor_name' => (string)($options['actor_name'] ?? ''),
                'reason' => (string)($options['reason'] ?? 'lock'),
                'metadata' => [
                    'lock_scope' => $scope,
                    'key' => $key,
                    'children' => $preview['children'],
                ],
            ],
        );
        if (empty($lockBatch['success'])) {
            return $lockBatch;
        }
        $lockVersionId = (int)($lockBatch['version_id'] ?? 0);

        // 回填 active_lock_version
        $parentAfter = $this->config->getScopedConfigRow($key, $module, $area, $scope, $locale);
        $this->config->saveScopeConfig(
            $module,
            $area,
            [$key => $this->rawValueFromRow($parentAfter ?? $parentRow)],
            $scope,
            $locale,
            [
                'operation' => self::OP_LOCK,
                'base_versions' => [$key => (int)(($parentAfter ?? $parentRow)[SystemConfig::schema_fields_VERSION] ?? 0)],
                'field_metadata' => [
                    $key => $this->mergeMetadata($parentAfter ?? $parentRow, [
                        self::META_ACTIVE_LOCK => $lockVersionId,
                        self::META_LOCKED_AT => \gmdate('c'),
                    ]),
                ],
                'value_types' => [$key => (string)(($parentAfter ?? $parentRow)[SystemConfig::schema_fields_VALUE_TYPE] ?? 'string')],
                'is_sensitive_values' => [$key => (int)(($parentAfter ?? $parentRow)[SystemConfig::schema_fields_IS_SENSITIVE] ?? 0)],
                'reason' => 'lock_set_active_version',
                'metadata' => ['lock_version_id' => $lockVersionId],
            ],
        );

        $suppressed = [];
        foreach ($preview['children'] as $child) {
            $childScope = (string)$child['scope'];
            $childLocale = (string)($child['locale'] ?? $locale);
            $childRow = $this->config->getScopedConfigRow($key, $module, $area, $childScope, $childLocale);
            if ($childRow === null) {
                continue;
            }
            $result = $this->config->saveScopeConfig(
                $module,
                $area,
                [$key => $this->rawValueFromRow($childRow)],
                $childScope,
                $childLocale,
                [
                    'operation' => self::OP_LOCK,
                    'base_versions' => [$key => (int)($childRow[SystemConfig::schema_fields_VERSION] ?? 0)],
                    'field_metadata' => [
                        $key => $this->mergeMetadata($childRow, [
                            self::META_SUPPRESSED_BY => $lockVersionId,
                            self::META_SUPPRESSED_AT => \gmdate('c'),
                            self::META_SUPPRESSED_FROM => $scope,
                        ]),
                    ],
                    'value_types' => [$key => (string)($childRow[SystemConfig::schema_fields_VALUE_TYPE] ?? 'string')],
                    'is_sensitive_values' => [$key => (int)($childRow[SystemConfig::schema_fields_IS_SENSITIVE] ?? 0)],
                    'reason' => 'suppress_by_lock',
                    'metadata' => [
                        'suppressed_by_lock_version' => $lockVersionId,
                        'from_scope' => $scope,
                    ],
                ],
            );
            if (!empty($result['success'])) {
                $suppressed[] = [
                    'scope' => $childScope,
                    'locale' => $childLocale,
                    'version_id' => (int)($result['version_id'] ?? 0),
                ];
            }
        }

        return [
            'success' => true,
            'status' => 'locked',
            'lock_version_id' => $lockVersionId,
            'parent_scope' => $scope,
            'suppressed' => $suppressed,
            'preview_count' => $preview['count'],
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function unlockScope(
        string $module,
        string $area,
        string $key,
        ?string $scope = null,
        ?string $locale = null,
        array $options = [],
    ): array {
        $scope = $this->config->normalizeScope($scope);
        $locale = $this->config->normalizeLocale($locale);
        $parentRow = $this->config->getScopedConfigRow($key, $module, $area, $scope, $locale);
        if ($parentRow === null) {
            return ['success' => false, 'status' => 'parent_missing'];
        }
        $meta = $this->decodeMetadata($parentRow);
        $lockVersion = (int)($meta[self::META_ACTIVE_LOCK] ?? 0);
        unset($meta[self::META_ACTIVE_LOCK], $meta[self::META_LOCKED_AT]);

        $result = $this->config->saveScopeConfig(
            $module,
            $area,
            [$key => $this->rawValueFromRow($parentRow)],
            $scope,
            $locale,
            [
                'operation' => self::OP_UNLOCK,
                'base_versions' => [$key => (int)($parentRow[SystemConfig::schema_fields_VERSION] ?? 0)],
                'field_metadata' => [$key => $meta],
                'value_types' => [$key => (string)($parentRow[SystemConfig::schema_fields_VALUE_TYPE] ?? 'string')],
                'is_sensitive_values' => [$key => (int)($parentRow[SystemConfig::schema_fields_IS_SENSITIVE] ?? 0)],
                'reason' => (string)($options['reason'] ?? 'unlock'),
                'metadata' => [
                    'unlocked_lock_version' => $lockVersion,
                    'note' => 'children remain suppressed until explicit restore/discard',
                ],
            ],
        );
        if (empty($result['success'])) {
            return $result;
        }

        return [
            'success' => true,
            'status' => 'unlocked',
            'previous_lock_version' => $lockVersion,
            'children_auto_restored' => false,
        ];
    }

    /**
     * @return array{rows:list<array<string,mixed>>,count:int}
     */
    public function previewRestoreSuppressed(
        string $module,
        string $area,
        string $key,
        ?string $parentScope = null,
        ?string $locale = null,
    ): array {
        $parentScope = $this->config->normalizeScope($parentScope);
        $locale = $this->config->normalizeLocale($locale);
        $rows = [];
        foreach ($this->listDescendantOverrides($module, $area, $key, $parentScope, $locale, true) as $child) {
            if (!empty($child['suppressed'])) {
                $rows[] = $child;
            }
        }

        return ['rows' => $rows, 'count' => \count($rows)];
    }

    /**
     * @param list<array{scope:string,locale?:string}> $targets
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function restoreSuppressedRows(
        string $module,
        string $area,
        string $key,
        array $targets,
        array $options = [],
    ): array {
        $restored = [];
        $conflicts = [];
        foreach ($targets as $target) {
            $scope = $this->config->normalizeScope((string)($target['scope'] ?? ''));
            $localeSpecified = \array_key_exists('locale', $target);
            $locales = $localeSpecified
                ? [$this->config->normalizeLocale((string)($target['locale'] ?? SystemConfig::LOCALE_DEFAULT))]
                : $this->localesForSuppressedScope($module, $area, $key, $scope);

            foreach ($locales as $locale) {
                $row = $this->config->getScopedConfigRow($key, $module, $area, $scope, $locale);
                if ($row === null || !self::isRowSuppressed($row)) {
                    continue;
                }
                $expected = $options['base_versions'][$key . '@' . $scope] ?? $options['base_versions'][$key] ?? null;
                if ($expected !== null && (int)($row[SystemConfig::schema_fields_VERSION] ?? 0) !== (int)$expected) {
                    $conflicts[] = [
                        'key' => $key,
                        'scope' => $scope,
                        'locale' => $locale,
                        'expected_version' => (int)$expected,
                        'current_version' => (int)($row[SystemConfig::schema_fields_VERSION] ?? 0),
                    ];
                    continue;
                }
                $meta = $this->decodeMetadata($row);
                unset($meta[self::META_SUPPRESSED_BY], $meta[self::META_SUPPRESSED_AT], $meta[self::META_SUPPRESSED_FROM]);
                $result = $this->config->saveScopeConfig(
                    $module,
                    $area,
                    [$key => $this->rawValueFromRow($row)],
                    $scope,
                    $locale,
                    [
                        'operation' => self::OP_RESTORE,
                        'base_versions' => [$key => (int)($row[SystemConfig::schema_fields_VERSION] ?? 0)],
                        'field_metadata' => [$key => $meta],
                        'value_types' => [$key => (string)($row[SystemConfig::schema_fields_VALUE_TYPE] ?? 'string')],
                        'is_sensitive_values' => [$key => (int)($row[SystemConfig::schema_fields_IS_SENSITIVE] ?? 0)],
                        'reason' => (string)($options['reason'] ?? 'restore_suppressed'),
                    ],
                );
                if (!empty($result['success'])) {
                    $restored[] = ['scope' => $scope, 'locale' => $locale];
                }
            }
        }
        if ($conflicts !== []) {
            return ['success' => false, 'status' => 'conflict', 'conflicts' => $conflicts, 'restored' => $restored];
        }

        return ['success' => true, 'status' => 'restored', 'restored' => $restored];
    }

    /**
     * @param list<array{scope:string,locale?:string}> $targets
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function discardSuppressedRows(
        string $module,
        string $area,
        string $key,
        array $targets,
        array $options = [],
    ): array {
        $discarded = [];
        foreach ($targets as $target) {
            $scope = $this->config->normalizeScope((string)($target['scope'] ?? ''));
            $localeSpecified = \array_key_exists('locale', $target);
            $locales = $localeSpecified
                ? [$this->config->normalizeLocale((string)($target['locale'] ?? SystemConfig::LOCALE_DEFAULT))]
                : $this->localesForSuppressedScope($module, $area, $key, $scope);

            foreach ($locales as $locale) {
                $row = $this->config->getScopedConfigRow($key, $module, $area, $scope, $locale);
                if ($row === null) {
                    continue;
                }
                $result = $this->config->deleteScopedConfig($key, $module, $area, $scope, $locale, [
                    'operation' => self::OP_DISCARD,
                    'base_versions' => [$key => (int)($row[SystemConfig::schema_fields_VERSION] ?? 0)],
                    'reason' => (string)($options['reason'] ?? 'discard_suppressed'),
                    'metadata' => ['discarded_suppressed' => true],
                ]);
                if ($result) {
                    $discarded[] = ['scope' => $scope, 'locale' => $locale];
                }
            }
        }

        return ['success' => true, 'status' => 'discarded', 'discarded' => $discarded];
    }

    /**
     * 未指定 locale 时，收集该 scope 下所有仍被 suppress 的 locale。
     *
     * @return list<string>
     */
    private function localesForSuppressedScope(string $module, string $area, string $key, string $scope): array
    {
        $locales = [];
        foreach ($this->config->listRowsForKey($module, $area, $key) as $row) {
            if (!\is_array($row)) {
                continue;
            }
            if ((string)($row[SystemConfig::schema_fields_SCOPE] ?? '') !== $scope) {
                continue;
            }
            if (!self::isRowSuppressed($row)) {
                continue;
            }
            $locales[] = (string)($row[SystemConfig::schema_fields_LOCALE] ?? SystemConfig::LOCALE_DEFAULT);
        }
        if ($locales === []) {
            // 兜底：当前请求 locale + default
            $locales = [
                $this->config->normalizeLocale(null),
                SystemConfig::LOCALE_DEFAULT,
            ];
        }

        return \array_values(\array_unique($locales));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function listDescendantOverrides(
        string $module,
        string $area,
        string $key,
        string $parentScope,
        string $locale,
        bool $includeSuppressedOnlyFilter = false,
    ): array {
        $rows = $this->config->listRowsForKey($module, $area, $key);
        $out = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $rowScope = (string)($row[SystemConfig::schema_fields_SCOPE] ?? '');
            $rowLocale = (string)($row[SystemConfig::schema_fields_LOCALE] ?? SystemConfig::LOCALE_DEFAULT);
            if ($rowScope === '' || $rowScope === $parentScope) {
                continue;
            }
            if (!self::isDescendantScope($parentScope, $rowScope)) {
                continue;
            }
            $suppressed = self::isRowSuppressed($row);
            if ($includeSuppressedOnlyFilter && !$suppressed) {
                continue;
            }
            $out[] = [
                'key' => $key,
                'scope' => $rowScope,
                'locale' => $rowLocale,
                'version' => (int)($row[SystemConfig::schema_fields_VERSION] ?? 0),
                'suppressed' => $suppressed,
                'value_type' => (string)($row[SystemConfig::schema_fields_VALUE_TYPE] ?? 'string'),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    private function mergeMetadata(array $row, array $patch): array
    {
        return \array_merge($this->decodeMetadata($row), $patch);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decodeMetadata(array $row): array
    {
        $meta = $row[SystemConfig::schema_fields_METADATA] ?? [];
        if (\is_string($meta)) {
            try {
                $decoded = \json_decode($meta, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $decoded = [];
            }
            $meta = $decoded;
        }

        return \is_array($meta) ? $meta : [];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rawValueFromRow(array $row): mixed
    {
        $type = (string)($row[SystemConfig::schema_fields_VALUE_TYPE] ?? 'string');
        $raw = $row[SystemConfig::schema_fields_VALUE] ?? null;
        // 交给 serialize 前尽量用已规范化值；字符串原样保存最安全
        if ($type === 'json' && \is_string($raw)) {
            try {
                return \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                return $raw;
            }
        }

        return $raw;
    }
}
