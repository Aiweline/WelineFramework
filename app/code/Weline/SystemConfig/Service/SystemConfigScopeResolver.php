<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Service;

use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ConfigScopeSource;
use Weline\SystemConfig\Api\Scope\ConfigScopeValue;
use Weline\SystemConfig\Model\SystemConfig;

/**
 * SystemConfig 四层 typed Scope 解析（TASK-P1C-001）。
 *
 * 固定链：Channel → Store → Website → Global。
 * 存储仍用三段 string；为区分 Global 与 website_code=default：
 * - Global → `default.default.default`
 * - Website(default) → `default.__website__.default`（`__website__` 非法 store 段，可作哨兵）
 */
final class SystemConfigScopeResolver
{
    public const KEY_VALUE = 'value';
    public const KEY_VERSION = 'version';
    public const KEY_SENSITIVE = 'is_sensitive';
    public const KEY_METADATA = 'metadata';
    public const KEY_SCOPE_KIND = 'scope_kind';

    /** Website(code=default) 专用存储哨兵（不可作为真实 store_code） */
    public const WEBSITE_DEFAULT_SENTINEL = '__website__';

    /**
     * @return list<string> 存储 scope 链（近→远）
     */
    public function chainFromIdentity(ScopeIdentity $identity): array
    {
        return match ($identity->scopeKind) {
            ScopeIdentity::KIND_GLOBAL => [SystemConfig::SCOPE_GLOBAL],
            ScopeIdentity::KIND_WEBSITE => [
                $this->toStorageScope($identity),
                SystemConfig::SCOPE_GLOBAL,
            ],
            ScopeIdentity::KIND_STORE => [
                $this->toStorageScope($identity),
                $this->toStorageScope(ScopeIdentity::website(
                    (int)$identity->websiteId,
                    (string)$identity->websiteCode,
                )),
                SystemConfig::SCOPE_GLOBAL,
            ],
            ScopeIdentity::KIND_CHANNEL => [
                $this->toStorageScope($identity),
                $this->toStorageScope(ScopeIdentity::store(
                    (int)$identity->websiteId,
                    (string)$identity->websiteCode,
                    (string)$identity->storeCode,
                    (string)$identity->storeMode,
                )),
                $this->toStorageScope(ScopeIdentity::website(
                    (int)$identity->websiteId,
                    (string)$identity->websiteCode,
                )),
                SystemConfig::SCOPE_GLOBAL,
            ],
            default => [SystemConfig::SCOPE_GLOBAL],
        };
    }

    public function toStorageScope(ScopeIdentity $identity): string
    {
        return match ($identity->scopeKind) {
            ScopeIdentity::KIND_GLOBAL => SystemConfig::SCOPE_GLOBAL,
            ScopeIdentity::KIND_WEBSITE => $this->websiteStorage((string)$identity->websiteCode),
            ScopeIdentity::KIND_STORE => \strtolower((string)$identity->websiteCode)
                . '.' . \strtolower((string)$identity->storeCode)
                . '.default',
            ScopeIdentity::KIND_CHANNEL => \strtolower((string)$identity->websiteCode)
                . '.' . \strtolower((string)$identity->storeCode)
                . '.' . \strtolower((string)$identity->channelCode),
            default => SystemConfig::SCOPE_GLOBAL,
        };
    }

    /**
     * 尽力从存储 scope 还原 Identity（无法识别时返回 null）。
     */
    public function fromStorageScope(string $storageScope): ?ScopeIdentity
    {
        $storageScope = \strtolower(\trim($storageScope));
        if ($storageScope === '' || $storageScope === SystemConfig::SCOPE_GLOBAL) {
            return ScopeIdentity::global();
        }
        $parts = \explode('.', $storageScope) + ['default', 'default', 'default'];
        [$w, $s, $c] = $parts;
        if ($s === self::WEBSITE_DEFAULT_SENTINEL && $c === 'default') {
            return ScopeIdentity::website(0, $w === '' ? 'default' : $w);
        }
        if ($s === 'default' && $c === 'default') {
            return ScopeIdentity::website(0, $w);
        }
        if ($c === 'default') {
            return ScopeIdentity::store(0, $w, $s, ScopeIdentity::MODE_NORMAL);
        }

        return ScopeIdentity::channel(0, $w, $s, $c, ScopeIdentity::MODE_NORMAL);
    }

    public static function recordKey(string $storageScope, string $locale = ''): string
    {
        $locale = \trim($locale);

        return $locale === '' ? $storageScope : $storageScope . "\0" . $locale;
    }

    /**
     * @param array<string, array{
     *   value?:mixed,
     *   version?:int,
     *   is_sensitive?:bool,
     *   metadata?:array<string,mixed>,
     *   scope_kind?:string
     * }> $records
     */
    public function resolveForIdentity(
        array $records,
        ScopeIdentity $identity,
        string $locale = '',
        mixed $default = null,
        bool $hasDefault = false,
    ): ConfigScopeValue {
        $locale = \trim($locale);
        $chain = $this->chainFromIdentity($identity);
        $localeOrder = $locale === '' ? [''] : [$locale, ''];
        $exactStorage = $this->toStorageScope($identity);

        foreach ($chain as $storageScope) {
            foreach ($localeOrder as $loc) {
                $key = self::recordKey($storageScope, $loc);
                if (!\array_key_exists($key, $records)) {
                    if ($loc === '' && \array_key_exists($storageScope, $records)) {
                        $key = $storageScope;
                    } else {
                        continue;
                    }
                }
                $row = $records[$key];
                if (!\array_key_exists(self::KEY_VALUE, $row)) {
                    continue;
                }
                $meta = \is_array($row[self::KEY_METADATA] ?? null) ? $row[self::KEY_METADATA] : [];
                // 防御：suppressed 行不参与解析（主过滤在 Model 加载侧）
                if (isset($meta[SystemConfigLockService::META_SUPPRESSED_BY])
                    && (int)$meta[SystemConfigLockService::META_SUPPRESSED_BY] > 0) {
                    continue;
                }
                $locked = isset($meta[SystemConfigLockService::META_ACTIVE_LOCK])
                    && (int)$meta[SystemConfigLockService::META_ACTIVE_LOCK] > 0;
                $sourceKind = $storageScope === $exactStorage
                    ? ConfigScopeSource::KIND_EXACT
                    : ConfigScopeSource::KIND_FALLBACK;
                $hitIdentity = $this->fromStorageScope($storageScope);
                $source = new ConfigScopeSource(
                    sourceKind: $sourceKind,
                    scopeKind: $hitIdentity?->scopeKind ?? ($row[self::KEY_SCOPE_KIND] ?? null),
                    storageScope: $storageScope,
                    locale: $loc === '' ? SystemConfig::LOCALE_DEFAULT : $loc,
                    version: isset($row[self::KEY_VERSION]) ? (int)$row[self::KEY_VERSION] : null,
                    isSensitive: !empty($row[self::KEY_SENSITIVE]),
                    metadata: $meta,
                    locked: $locked,
                    suppressed: false,
                );

                return new ConfigScopeValue(
                    value: $row[self::KEY_VALUE],
                    source: $source,
                    requestedScope: $identity,
                    requestedLocale: $locale === '' ? SystemConfig::LOCALE_DEFAULT : $locale,
                    fallbackStorageScopes: $chain,
                );
            }
        }

        if ($hasDefault) {
            return new ConfigScopeValue(
                value: $default,
                source: ConfigScopeSource::fromDefault(),
                requestedScope: $identity,
                requestedLocale: $locale === '' ? SystemConfig::LOCALE_DEFAULT : $locale,
                fallbackStorageScopes: $chain,
            );
        }

        return new ConfigScopeValue(
            value: null,
            source: ConfigScopeSource::unresolved(),
            requestedScope: $identity,
            requestedLocale: $locale === '' ? SystemConfig::LOCALE_DEFAULT : $locale,
            fallbackStorageScopes: $chain,
        );
    }

    /**
     * 拒绝短 scope 新写（`default` / `shop` / `shop.main`）；完整三段或 Identity 存储串放行。
     */
    public function assertWritableRawScope(?string $rawScope): void
    {
        if ($rawScope === null) {
            return;
        }
        $trimmed = \trim($rawScope);
        if ($trimmed === '') {
            return;
        }
        $parts = \array_values(\array_filter(
            \array_map('trim', \explode('.', \strtolower($trimmed))),
            static fn(string $s): bool => $s !== '',
        ));
        if ($parts !== [] && \count($parts) < 3) {
            throw new \InvalidArgumentException('system_config_short_scope_write_forbidden:' . $trimmed);
        }
    }

    private function websiteStorage(string $websiteCode): string
    {
        $websiteCode = \strtolower(\trim($websiteCode));
        if ($websiteCode === '' || $websiteCode === 'default') {
            return 'default.' . self::WEBSITE_DEFAULT_SENTINEL . '.default';
        }

        return $websiteCode . '.default.default';
    }
}
