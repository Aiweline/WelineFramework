<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Service;

use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ConfigScopeSource;
use Weline\SystemConfig\Api\Scope\ConfigScopeValue;
use Weline\SystemConfig\Api\Scope\ScopeContext;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\SystemConfig\Model\SystemConfig;

/**
 * SystemConfig 四层 typed Scope 解析（TASK-P1C-001）。
 *
 * 固定链：Channel → Store → Website → Global。
 * 存储仍用三段 string；为区分 Global 与 website_code=default：
 * - Global → `default.default.default`
 * - Website(default) → `default.__website__.default`（`__website__` 非法 store 段，可作哨兵）
 */
final class SystemConfigScopeResolver implements ScopeHierarchyInterface
{
    public const KEY_VALUE = 'value';
    public const KEY_VERSION = 'version';
    public const KEY_SENSITIVE = 'is_sensitive';
    public const KEY_METADATA = 'metadata';
    public const KEY_SCOPE_KIND = 'scope_kind';

    /** Website(code=default) 专用存储哨兵（不可作为真实 store_code） */
    public const WEBSITE_DEFAULT_SENTINEL = '__website__';

    /** Store(code=default) 专用存储哨兵。 */
    public const STORE_DEFAULT_SENTINEL = '__store__';

    /** Channel(code=default) 专用存储哨兵。 */
    public const CHANNEL_DEFAULT_SENTINEL = '__channel__';

    public function contextFromIdentity(ScopeIdentity $identity): ScopeContext
    {
        $storageScope = $this->toStorageScope($identity);

        return new ScopeContext(
            identity: $identity,
            storageScope: $storageScope,
            storeMode: (string)($identity->storeMode ?: ScopeIdentity::MODE_NORMAL),
            fallbackStorageScopes: $this->chainFromIdentity($identity),
        );
    }

    public function contextFromClaims(array $claims, ScopeIdentity $authoritativeIdentity): ScopeContext
    {
        $claimed = ScopeIdentity::fromArray($claims);
        if (!$claimed->equals($authoritativeIdentity)) {
            throw new \InvalidArgumentException('system_config_scope_claim_identity_mismatch');
        }

        return $this->contextFromIdentity($authoritativeIdentity);
    }

    /**
     * @return list<string> 存储 scope 链（近→远）
     */
    public function chainFromIdentity(ScopeIdentity $identity): array
    {
        $chain = [];
        $cursor = $identity;
        do {
            $chain[] = $this->toStorageScope($cursor);
            $cursor = $this->parentIdentity($cursor);
        } while ($cursor instanceof ScopeIdentity);

        return $chain;
    }

    public function parentIdentity(ScopeIdentity $identity): ?ScopeIdentity
    {
        return match ($identity->scopeKind) {
            ScopeIdentity::KIND_GLOBAL => null,
            ScopeIdentity::KIND_WEBSITE => ScopeIdentity::global($identity->contextVersion),
            ScopeIdentity::KIND_STORE => ScopeIdentity::website(
                (int)$identity->websiteId,
                (string)$identity->websiteCode,
                $identity->contextVersion,
            ),
            ScopeIdentity::KIND_CHANNEL => ScopeIdentity::store(
                (int)$identity->websiteId,
                (string)$identity->websiteCode,
                (string)$identity->storeCode,
                (string)$identity->storeMode,
                $identity->contextVersion,
            ),
            default => throw new \InvalidArgumentException('system_config_scope_kind_invalid'),
        };
    }

    public function toStorageScope(ScopeIdentity $identity): string
    {
        return match ($identity->scopeKind) {
            ScopeIdentity::KIND_GLOBAL => SystemConfig::SCOPE_GLOBAL,
            ScopeIdentity::KIND_WEBSITE => $this->websiteStorage((string)$identity->websiteCode),
            ScopeIdentity::KIND_STORE => \strtolower((string)$identity->websiteCode)
                . '.' . $this->storeStorageSegment((string)$identity->storeCode)
                . '.default',
            ScopeIdentity::KIND_CHANNEL => \strtolower((string)$identity->websiteCode)
                . '.' . $this->storeStorageSegment((string)$identity->storeCode)
                . '.' . $this->channelStorageSegment((string)$identity->channelCode),
            default => throw new \InvalidArgumentException('system_config_scope_kind_invalid'),
        };
    }

    /**
     * 尽力从存储 scope 还原 Identity（无法识别时返回 null）。
     */
    public function fromStorageScope(string $storageScope, bool $allowLegacy = true): ?ScopeIdentity
    {
        $storageScope = \strtolower(\trim($storageScope));
        if ($storageScope === '') {
            return $allowLegacy ? ScopeIdentity::global() : null;
        }
        if ($storageScope === SystemConfig::SCOPE_GLOBAL) {
            return ScopeIdentity::global();
        }
        $parts = \explode('.', $storageScope);
        if (\count($parts) !== 3) {
            if (!$allowLegacy || \count($parts) > 3) {
                return null;
            }
            while (\count($parts) < 3) {
                $parts[] = 'default';
            }
        }
        [$w, $s, $c] = $parts;
        if (!$this->isBusinessSegment($w, 255)) {
            return null;
        }
        if ($s === self::WEBSITE_DEFAULT_SENTINEL && $c === 'default') {
            return ScopeIdentity::website(0, $w === '' ? 'default' : $w);
        }
        // 2.0 compatibility: default Store was accidentally written as website.default.__store__.
        if ($allowLegacy && $s === 'default' && $c === self::STORE_DEFAULT_SENTINEL) {
            return ScopeIdentity::store(0, $w, 'default', ScopeIdentity::MODE_NORMAL);
        }
        if ($s === self::STORE_DEFAULT_SENTINEL) {
            if ($c === 'default') {
                return ScopeIdentity::store(0, $w, 'default', ScopeIdentity::MODE_NORMAL);
            }
            if ($c === self::CHANNEL_DEFAULT_SENTINEL) {
                return ScopeIdentity::channel(0, $w, 'default', 'default', ScopeIdentity::MODE_NORMAL);
            }
            if (!$this->isBusinessSegment($c)) {
                return null;
            }

            return ScopeIdentity::channel(0, $w, 'default', $c, ScopeIdentity::MODE_NORMAL);
        }
        if (!$this->isBusinessSegment($s)) {
            return null;
        }
        if ($s === 'default' && $c === 'default') {
            return ScopeIdentity::website(0, $w);
        }
        if ($c === 'default') {
            return ScopeIdentity::store(0, $w, $s, ScopeIdentity::MODE_NORMAL);
        }
        if ($c === self::CHANNEL_DEFAULT_SENTINEL) {
            return ScopeIdentity::channel(0, $w, $s, 'default', ScopeIdentity::MODE_NORMAL);
        }
        if (!$this->isBusinessSegment($c)) {
            return null;
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
        $parts = \explode('.', \strtolower($trimmed));
        if (\count($parts) !== 3 || \in_array('', $parts, true)) {
            throw new \InvalidArgumentException('system_config_short_scope_write_forbidden:' . $trimmed);
        }
        $identity = $this->fromStorageScope($trimmed, false);
        if (!$identity instanceof ScopeIdentity || $this->toStorageScope($identity) !== \strtolower($trimmed)) {
            throw new \InvalidArgumentException('system_config_noncanonical_scope_write_forbidden:' . $trimmed);
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

    private function storeStorageSegment(string $storeCode): string
    {
        $storeCode = \strtolower(\trim($storeCode));

        return $storeCode === 'default' ? self::STORE_DEFAULT_SENTINEL : $storeCode;
    }

    private function channelStorageSegment(string $channelCode): string
    {
        $channelCode = \strtolower(\trim($channelCode));

        return $channelCode === 'default' ? self::CHANNEL_DEFAULT_SENTINEL : $channelCode;
    }

    private function isBusinessSegment(string $value, int $maxLength = 64): bool
    {
        $maxTail = $maxLength - 1;

        return \preg_match('/^[a-z0-9][a-z0-9_-]{0,' . $maxTail . '}$/D', $value) === 1;
    }
}
