<?php

declare(strict_types=1);

namespace Weline\Meta\Service;

use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Meta\Api\Data\MetaConfigIdentity;
use Weline\Meta\Api\MetaConfigRepositoryInterface;
use Weline\Meta\Api\Scope\MetaConfigScopeSource;
use Weline\Meta\Api\Scope\MetaConfigScopeValue;
use Weline\SystemConfig\Service\SystemConfigScopeResolver;

/**
 * TASK-P1C-005-META：在精确 Repository 之上叠加 typed Scope 回落。
 * 旧 resolve()/upsert() 仍精确匹配；本服务只做只读 typed 解析。
 */
final class MetaConfigTypedScopeService
{
    public function __construct(
        private readonly MetaConfigRepositoryInterface $repository,
        private readonly SystemConfigScopeResolver $scopeResolver,
    ) {
    }

    public function resolveTyped(
        string $namespace,
        string $configKey,
        ScopeIdentity $identity,
        ?string $locale,
        ?string $identifyId = null,
        ?int $metaId = null,
        ?string $metaIdentify = null,
    ): MetaConfigScopeValue {
        // identifyId="0" 合法；未给 owner 时用零所有者占位，避免 Identity 构造失败。
        if (($identifyId === null || trim($identifyId) === '')
            && $metaId === null
            && ($metaIdentify === null || trim($metaIdentify) === '')
        ) {
            $identifyId = '0';
        }

        $chain = $this->scopeResolver->chainFromIdentity($identity);
        $exactStorage = $this->scopeResolver->toStorageScope($identity);

        foreach ($chain as $storageScope) {
            $record = $this->repository->resolve(new MetaConfigIdentity(
                namespace: $namespace,
                configKey: $configKey,
                scope: $storageScope,
                locale: $locale,
                identifyId: $identifyId,
                metaId: $metaId,
                metaIdentify: $metaIdentify,
            ));
            if ($record === null) {
                // 兼容历史短 scope（如 default）仅在 Website/Global 层尝试一次
                $legacy = $this->legacyCompatScope($storageScope);
                if ($legacy !== null && $legacy !== $storageScope) {
                    $record = $this->repository->resolve(new MetaConfigIdentity(
                        namespace: $namespace,
                        configKey: $configKey,
                        scope: $legacy,
                        locale: $locale,
                        identifyId: $identifyId,
                        metaId: $metaId,
                        metaIdentify: $metaIdentify,
                    ));
                }
            }
            if ($record === null) {
                continue;
            }
            $hitIdentity = $this->scopeResolver->fromStorageScope($storageScope);
            $sourceKind = $storageScope === $exactStorage
                ? MetaConfigScopeSource::KIND_EXACT
                : MetaConfigScopeSource::KIND_FALLBACK;

            return new MetaConfigScopeValue(
                record: $record,
                source: new MetaConfigScopeSource(
                    sourceKind: $sourceKind,
                    scopeKind: $hitIdentity?->scopeKind,
                    storageScope: $storageScope,
                    locale: $record->locale,
                    version: null,
                    metadata: [],
                ),
                requestedScope: $identity,
                requestedLocale: $locale,
                fallbackStorageScopes: $chain,
            );
        }

        return new MetaConfigScopeValue(
            record: null,
            source: MetaConfigScopeSource::unresolved(),
            requestedScope: $identity,
            requestedLocale: $locale,
            fallbackStorageScopes: $chain,
        );
    }

    /**
     * 写路径：将 Identity 转为规范三段存储串；拒绝短 scope。
     */
    public function toWritableStorageScope(ScopeIdentity|string $scope): string
    {
        if ($scope instanceof ScopeIdentity) {
            return $this->scopeResolver->toStorageScope($scope);
        }
        $this->scopeResolver->assertWritableRawScope($scope);

        return $this->normalizeStorage((string)$scope);
    }

    private function legacyCompatScope(string $storageScope): ?string
    {
        if ($storageScope === 'default.default.default') {
            return 'default';
        }
        if (str_ends_with($storageScope, '.default.default')) {
            $website = explode('.', $storageScope, 2)[0] ?? '';

            return $website !== '' ? $website : null;
        }

        return null;
    }

    private function normalizeStorage(string $scope): string
    {
        $scope = strtolower(trim($scope));
        if ($scope === '' || $scope === 'default') {
            return 'default.default.default';
        }
        $parts = array_values(array_filter(explode('.', $scope), static fn(string $p): bool => $p !== ''));
        while (count($parts) < 3) {
            $parts[] = 'default';
        }

        return implode('.', array_slice($parts, 0, 3));
    }
}
