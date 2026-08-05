<?php

declare(strict_types=1);

namespace Weline\Cdn\Service;

use Weline\Cdn\Api\ScopedAccountBindingRepositoryInterface;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Service\SystemConfigScopeResolver;

/**
 * CDN/媒体账户 Scope 授权绑定（TASK-P1D-002 / TEST-P1D-02）。
 *
 * - 键：storageScope + store_mode + adapter（test/normal 隔离）
 * - 解析：Channel→Store→Website→Global 链；无本地绑定则回 Global 已授权别名
 * - restoreInheritance：删除本 Scope 覆盖，恢复父级/Global
 */
final class ScopedAccountBindingService
{
    public const ADAPTER_MEDIA = 'media';

    public function __construct(
        private readonly SystemConfigScopeResolver $scopeResolver,
        private readonly ScopedAccountBindingRepositoryInterface $repository,
    ) {
    }

    /**
     * @return array{
     *   account_id:int,
     *   adapter:string,
     *   media_base_url:string,
     *   global_alias:string,
     *   storage_scope:string,
     *   store_mode:string
     * }
     */
    public function bind(
        ScopeIdentity $scope,
        string $adapter,
        int $accountId,
        string $mediaBaseUrl = '',
        string $globalAlias = '',
    ): array {
        if ($accountId <= 0) {
            throw new \InvalidArgumentException('cdn_account_binding_invalid_account');
        }
        $adapter = $this->normalizeAdapter($adapter);
        $storage = $this->scopeResolver->toStorageScope($scope);
        $mode = $this->normalizeStoreMode($scope->storeMode);
        $mediaBaseUrl = \rtrim(\trim($mediaBaseUrl), '/');
        if ($mediaBaseUrl !== '') {
            if (\strlen($mediaBaseUrl) > 1024
                || \filter_var($mediaBaseUrl, \FILTER_VALIDATE_URL) === false
                || !\in_array(
                    \strtolower((string)\parse_url($mediaBaseUrl, \PHP_URL_SCHEME)),
                    ['http', 'https'],
                    true,
                )) {
                throw new \InvalidArgumentException('cdn_account_binding_media_url_invalid');
            }
        }
        $globalAlias = \trim($globalAlias);
        if (\strlen($globalAlias) > 191
            || \preg_match('/[\x00-\x1F\x7F]/D', $globalAlias) === 1) {
            throw new \InvalidArgumentException('cdn_account_binding_global_alias_invalid');
        }

        return $this->repository->save(
            $storage,
            $mode,
            $adapter,
            $accountId,
            $mediaBaseUrl,
            $globalAlias,
        );
    }

    /**
     * @return array{
     *   account_id:int,
     *   adapter:string,
     *   media_base_url:string,
     *   global_alias:string,
     *   storage_scope:string,
     *   store_mode:string,
     *   source_kind:string
     * }|null
     */
    public function resolve(ScopeIdentity $scope, string $adapter): ?array
    {
        $adapter = $this->normalizeAdapter($adapter);
        $mode = $this->normalizeStoreMode($scope->storeMode);
        $exact = $this->scopeResolver->toStorageScope($scope);
        foreach ($this->scopeResolver->chainFromIdentity($scope) as $storage) {
            $hit = $this->repository->find($storage, $mode, $adapter);
            if ($hit === null) {
                continue;
            }
            $hit['source_kind'] = $storage === $exact ? 'exact' : 'fallback';

            return $hit;
        }

        return null;
    }

    public function restoreInheritance(ScopeIdentity $scope, string $adapter): bool
    {
        return $this->repository->delete(
            $this->scopeResolver->toStorageScope($scope),
            $this->normalizeStoreMode($scope->storeMode),
            $this->normalizeAdapter($adapter),
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listForMode(string $storeMode): array
    {
        return $this->repository->listForMode($this->normalizeStoreMode($storeMode));
    }

    private function normalizeAdapter(string $adapter): string
    {
        $adapter = \strtolower(\trim($adapter));
        if (\preg_match('/^[a-z0-9][a-z0-9_-]{0,49}$/D', $adapter) !== 1) {
            throw new \InvalidArgumentException('cdn_account_binding_adapter_invalid');
        }

        return $adapter;
    }

    private function normalizeStoreMode(?string $storeMode): string
    {
        $storeMode = \strtolower(\trim((string)($storeMode ?: ScopeIdentity::MODE_NORMAL)));
        if (!\in_array(
            $storeMode,
            [ScopeIdentity::MODE_NORMAL, ScopeIdentity::MODE_DEV, ScopeIdentity::MODE_TEST],
            true,
        )) {
            throw new \InvalidArgumentException('cdn_account_binding_store_mode_invalid');
        }

        return $storeMode;
    }
}
