<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Service\SystemConfigScopeResolver;

/**
 * TASK-P1C-005-THEME：布局 identity 的 typed Scope + store_mode 隔离。
 *
 * 存储：规范三段 scope；非 normal 的 store_mode 以 `~{mode}` 后缀隔离草稿/发布行。
 * 读兼容历史短 scope `default`。
 */
final class ThemeLayoutScopeNormalizer
{
    public const MODE_SEPARATOR = '~';

    public function __construct(
        private readonly SystemConfigScopeResolver $scopeResolver,
    ) {
    }

    /**
     * @param array<string, mixed> $identity
     * @return array{layout_option:string,scope:string,target_type:string,target_id:int,store_mode:string,storage_scope:string}
     */
    public function normalize(array $identity = []): array
    {
        $layoutOption = trim((string)($identity['layout_option'] ?? 'default'));
        $targetType = trim((string)($identity['target_type'] ?? 'global'));
        $storeMode = strtolower(trim((string)($identity['store_mode'] ?? ScopeIdentity::MODE_NORMAL)));
        if ($storeMode === '') {
            $storeMode = ScopeIdentity::MODE_NORMAL;
        }

        $rawScope = trim((string)($identity['scope'] ?? ''));
        $decoded = $this->decodeStorageScope($rawScope !== '' ? $rawScope : 'default');
        $storageScope = $decoded['storage_scope'];
        if (isset($identity['store_mode']) && trim((string)$identity['store_mode']) !== '') {
            $storeMode = strtolower(trim((string)$identity['store_mode']));
        } elseif ($decoded['store_mode'] !== ScopeIdentity::MODE_NORMAL) {
            $storeMode = $decoded['store_mode'];
        }

        // 允许传入 ScopeIdentity
        if (($identity['scope_identity'] ?? null) instanceof ScopeIdentity) {
            /** @var ScopeIdentity $si */
            $si = $identity['scope_identity'];
            $storageScope = $this->scopeResolver->toStorageScope($si);
            if ($si->storeMode) {
                $storeMode = strtolower((string)$si->storeMode);
            }
        } else {
            try {
                $this->scopeResolver->assertWritableRawScope($storageScope);
            } catch (\Throwable) {
                // 短 scope → 升格
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
        ];
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
                $mode = $modePart;
            }
        }
        $storage = $this->upgradeShortScope($raw !== '' ? $raw : 'default');

        return ['storage_scope' => $storage, 'store_mode' => $mode];
    }

    public function encodeStorageScope(string $storageScope, string $storeMode): string
    {
        $storageScope = $this->upgradeShortScope($storageScope);
        $storeMode = strtolower(trim($storeMode));
        if ($storeMode === '' || $storeMode === ScopeIdentity::MODE_NORMAL) {
            return $storageScope;
        }

        return $storageScope . self::MODE_SEPARATOR . $storeMode;
    }

    public function upgradeShortScope(string $scope): string
    {
        $original = trim($scope);
        $scope = strtolower($original);
        if ($scope === '' || $scope === 'default') {
            return 'default.default.default';
        }

        // Preserve opaque layout scopes (e.g. dashboard_view:{id}) that are not
        // SystemConfig dotted triples. saveWidget must keep them byte-stable with
        // LayoutWorkspace readers that query the raw Dashboard identity scope.
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
}
