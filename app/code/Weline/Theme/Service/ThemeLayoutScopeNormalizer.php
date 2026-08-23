<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\Theme\Api\Layout\LayoutScopeNormalizerInterface;

/**
 * TASK-P1C-005-THEME：布局 identity 的 typed Scope + store_mode 隔离。
 *
 * 存储：规范三段 scope；非 normal 的 store_mode 以 `~{mode}` 后缀隔离草稿/发布行。
 * 读兼容历史短 scope `default`。
 */
final class ThemeLayoutScopeNormalizer implements LayoutScopeNormalizerInterface
{
    public const MODE_SEPARATOR = '~';

    public function __construct(
        private readonly ScopeHierarchyInterface $scopeResolver,
    ) {
    }

    /**
     * @param array<string, mixed> $identity
     * @return array{layout_option:string,scope:string,target_type:string,target_id:int,store_mode:string,storage_scope:string,locale_code:string}
     */
    public function normalize(array $identity = []): array
    {
        $layoutOption = trim((string)($identity['layout_option'] ?? 'default'));
        $targetType = trim((string)($identity['target_type'] ?? 'global'));
        $localeCode = $this->normalizeLocaleCode((string)($identity['locale_code'] ?? $identity['locale'] ?? ''));
        $storeMode = $this->normalizeStoreMode((string)($identity['store_mode'] ?? ScopeIdentity::MODE_NORMAL));

        $rawScope = trim((string)($identity['scope'] ?? ''));
        $decoded = $this->decodeStorageScope($rawScope !== '' ? $rawScope : 'default');
        $storageScope = $decoded['storage_scope'];
        if (isset($identity['store_mode']) && trim((string)$identity['store_mode']) !== '') {
            $storeMode = $this->normalizeStoreMode((string)$identity['store_mode']);
        } elseif ($decoded['store_mode'] !== ScopeIdentity::MODE_NORMAL) {
            $storeMode = $decoded['store_mode'];
        }

        // 允许传入 ScopeIdentity。Theme 为默认店铺保留独立哨兵，避免与网站/全局 scope 折叠。
        if (($identity['scope_identity'] ?? null) instanceof ScopeIdentity) {
            /** @var ScopeIdentity $si */
            $si = $identity['scope_identity'];
            $storageScope = $this->scopeResolver->toStorageScope($si);
            if ($si->storeMode) {
                $storeMode = $this->normalizeStoreMode((string)$si->storeMode);
            }
        } else {
            try {
                $this->scopeResolver->assertWritableRawScope($storageScope);
            } catch (\Throwable) {
                // 历史短/opaque scope 只在兼容投影中升格或保留。
                $storageScope = $this->upgradeShortScope($storageScope);
            }
        }

        return [
            'layout_option' => $layoutOption !== '' ? $layoutOption : 'default',
            'scope' => $this->encodeStorageScope($storageScope, $storeMode),
            'storage_scope' => $storageScope,
            'store_mode' => $storeMode,
            'target_type' => $targetType !== '' ? $targetType : 'global',
            'target_id' => max(0, (int)($identity['target_id'] ?? 0)),
            'locale_code' => $localeCode,
        ];
    }

    private function normalizeLocaleCode(string $localeCode): string
    {
        $localeCode = trim(str_replace('-', '_', $localeCode));
        if ($localeCode === '') {
            return '';
        }
        if (preg_match('/^[a-zA-Z]{2,3}(?:_[a-zA-Z]{4})?(?:_(?:[a-zA-Z]{2}|[0-9]{3}))?$/D', $localeCode) !== 1) {
            throw new \InvalidArgumentException((string)__('布局语言代码无效：%{1}', [$localeCode]));
        }
        $parts = explode('_', $localeCode);
        $parts[0] = strtolower($parts[0]);
        if (isset($parts[1])) {
            $parts[1] = strlen($parts[1]) === 4 ? ucfirst(strtolower($parts[1])) : strtoupper($parts[1]);
        }
        if (isset($parts[2])) {
            $parts[2] = strtoupper($parts[2]);
        }
        return implode('_', $parts);
    }

    /**
     * @return array{storage_scope:string,store_mode:string}
     */
    public function decodeStorageScope(string $raw): array
    {
        $raw = trim($raw);
        $mode = ScopeIdentity::MODE_NORMAL;
        if (str_contains($raw, self::MODE_SEPARATOR)) {
            [$scopePart, $modePart] = explode(self::MODE_SEPARATOR, $raw, 2);
            $raw = trim($scopePart);
            $modePart = strtolower(trim($modePart));
            if ($modePart !== '') {
                $mode = $this->normalizeStoreMode($modePart);
            }
        }
        $storage = $this->upgradeShortScope($raw !== '' ? $raw : 'default');

        return ['storage_scope' => $storage, 'store_mode' => $mode];
    }

    public function encodeStorageScope(string $storageScope, string $storeMode): string
    {
        $storageScope = $this->upgradeShortScope($storageScope);
        $storeMode = $this->normalizeStoreMode($storeMode);
        if ($storeMode === ScopeIdentity::MODE_NORMAL) {
            return $storageScope;
        }

        return $storageScope . self::MODE_SEPARATOR . $storeMode;
    }

    public function identityFromEncodedScope(string $encodedScope): ScopeIdentity
    {
        $decoded = $this->decodeStorageScope($encodedScope);
        $identity = $this->scopeResolver->fromStorageScope($decoded['storage_scope'], true);
        if (!$identity instanceof ScopeIdentity) {
            throw new \InvalidArgumentException((string)__('Theme Scope 无法解析为 ScopeIdentity。'));
        }

        return $this->withStoreMode($identity, $decoded['store_mode']);
    }

    public function upgradeShortScope(string $scope): string
    {
        $original = trim($scope);
        $scope = strtolower($original);
        if ($scope === '' || $scope === 'default') {
            return 'default.default.default';
        }

        // Unknown opaque identities remain byte-stable only for compatibility
        // reads. Controller write boundaries require typed canonical Scope, and
        // known legacy identities are rewritten by module migration providers.
        if (str_contains($original, ':') || str_starts_with($scope, 'dashboard_view')) {
            return $original;
        }

        $parts = array_values(array_filter(explode('.', $scope), static fn(string $p): bool => $p !== ''));
        if (count($parts) >= 3) {
            return implode('.', array_slice($parts, 0, 3));
        }
        while (count($parts) < 3) {
            $parts[] = 'default';
        }

        return implode('.', $parts);
    }

    /**
     * 兼容读：同一逻辑 scope 下可能存在历史短串。
     *
     * @return list<string>
     */
    public function readCandidateScopes(string $encodedScope): array
    {
        $decoded = $this->decodeStorageScope($encodedScope);
        $primary = $this->encodeStorageScope($decoded['storage_scope'], $decoded['store_mode']);
        $candidates = [$primary];
        if ($decoded['store_mode'] === ScopeIdentity::MODE_NORMAL
            && $decoded['storage_scope'] === 'default.default.default') {
            $candidates[] = 'default';
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Runtime compatibility chain for legacy Theme readers.
     *
     * New ownership is resolved by Theme scoped releases. Legacy layout/meta
     * projections still need the same nearest-to-farthest chain when a child
     * Scope has never created a workspace. Store mode is retained only while
     * the cursor is at Store/Channel; Website and Global are mode-neutral.
     *
     * @return list<string>
     */
    public function readFallbackScopes(string $encodedScope): array
    {
        $decoded = $this->decodeStorageScope($encodedScope);
        $identity = $this->scopeResolver->fromStorageScope($decoded['storage_scope'], true);
        if (!$identity instanceof ScopeIdentity) {
            return $this->readCandidateScopes($encodedScope);
        }

        $identity = $this->withStoreMode($identity, $decoded['store_mode']);
        $scopes = [];
        $cursor = $identity;
        do {
            $context = $this->scopeResolver->contextFromIdentity($cursor);
            $scopes[] = $this->encodeStorageScope($context->storageScope, $context->storeMode);
            $cursor = $this->scopeResolver->parentIdentity($cursor);
        } while ($cursor instanceof ScopeIdentity);

        // Phase-compatible read of the historical Global bucket.
        if (in_array('default.default.default', $scopes, true)) {
            $scopes[] = 'default';
        }

        return array_values(array_unique($scopes));
    }

    private function withStoreMode(ScopeIdentity $identity, string $storeMode): ScopeIdentity
    {
        if ($storeMode === ScopeIdentity::MODE_NORMAL) {
            return $identity;
        }

        return match ($identity->scopeKind) {
            ScopeIdentity::KIND_STORE => ScopeIdentity::store(
                (int)$identity->websiteId,
                (string)$identity->websiteCode,
                (string)$identity->storeCode,
                $storeMode,
                $identity->contextVersion,
            ),
            ScopeIdentity::KIND_CHANNEL => ScopeIdentity::channel(
                (int)$identity->websiteId,
                (string)$identity->websiteCode,
                (string)$identity->storeCode,
                (string)$identity->channelCode,
                $storeMode,
                $identity->contextVersion,
            ),
            default => $identity,
        };
    }

    private function normalizeStoreMode(string $storeMode): string
    {
        $storeMode = strtolower(trim($storeMode));
        if ($storeMode === '') {
            $storeMode = ScopeIdentity::MODE_NORMAL;
        }
        if (!in_array($storeMode, [
            ScopeIdentity::MODE_NORMAL,
            ScopeIdentity::MODE_DEV,
            ScopeIdentity::MODE_TEST,
        ], true)) {
            throw new \InvalidArgumentException((string)__('Theme Store Mode 无效：%{1}', [$storeMode]));
        }
        return $storeMode;
    }
}
