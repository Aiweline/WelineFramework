<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Service;

use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\SystemConfig\Api\Scope\ScopeIdentityCatalogInterface;
use Weline\SystemConfig\Api\Scope\ScopeUiStateInterface;
use Weline\SystemConfig\Model\SystemConfig;

/**
 * TASK-P1C-004：配置中心工作 TargetScope（三段选择）。
 *
 * - Session 仅用于 UI 恢复，不得作为写目标权威来源
 * - 写路径必须使用表单显式 target_scope / website+store+channel
 */
final class SystemConfigTargetScopeService
{
    public const SESSION_KEY = 'system_config.work_target_scope';
    public const KIND_GLOBAL = 'global';
    public const KIND_WEBSITE = 'website';
    public const KIND_STORE = 'store';
    public const KIND_CHANNEL = 'channel';

    public function __construct(
        private readonly ScopeHierarchyInterface $resolver,
        private readonly ScopeIdentityCatalogInterface $identityCatalog,
        private readonly ScopeUiStateInterface $uiState,
    ) {
    }

    /**
     * @param array<string, mixed> $input website_code/store_code/channel_code 或 target_scope/scope
     * @return array{
     *   kind:string,
     *   website_code:string,
     *   store_code:string,
     *   channel_code:string,
     *   store_mode:string,
     *   storage_scope:string,
     *   identity:\Weline\Framework\Runtime\ScopeIdentity
     * }
     */
    public function resolveFromInput(array $input, bool $allowSessionFallback = false): array
    {
        $explicit = trim((string)($input['target_scope'] ?? $input['scope'] ?? ''));
        $website = strtolower(trim((string)($input['website_code'] ?? $input['target_website'] ?? '')));
        $store = strtolower(trim((string)($input['store_code'] ?? $input['target_store'] ?? '')));
        $channel = strtolower(trim((string)($input['channel_code'] ?? $input['target_channel'] ?? '')));
        $scopeKind = strtolower(trim((string)($input['scope_kind'] ?? $input['kind'] ?? '')));
        $storeMode = strtolower(trim((string)($input['store_mode'] ?? ScopeIdentity::MODE_NORMAL)));
        $hasSegmentFields = \array_key_exists('website_code', $input)
            || \array_key_exists('target_website', $input)
            || \array_key_exists('store_code', $input)
            || \array_key_exists('channel_code', $input);

        if ($hasSegmentFields) {
            return $this->fromParts($website, $store, $channel, $scopeKind, $storeMode);
        }

        if ($website !== '' || $store !== '' || $channel !== '') {
            return $this->fromParts($website, $store, $channel, $scopeKind, $storeMode);
        }

        if ($explicit !== '') {
            $this->resolver->assertWritableRawScope($explicit);
            $normalized = $this->normalizeStorage($explicit);
            $identity = $this->resolveAuthoritativeIdentity(
                $this->withStoreMode(
                    $this->resolver->fromStorageScope($normalized) ?? ScopeIdentity::global(),
                    $storeMode,
                ),
            );

            return $this->pack($identity, $this->resolver->toStorageScope($identity));
        }

        if ($allowSessionFallback) {
            $sessionScope = $this->readSessionScope();
            if ($sessionScope !== null) {
                return $sessionScope;
            }
        }

        return $this->pack(ScopeIdentity::global(), SystemConfig::SCOPE_GLOBAL);
    }

    /**
     * @return array{kind:string,website_code:string,store_code:string,channel_code:string,store_mode:string,storage_scope:string,identity:ScopeIdentity}
     */
    public function fromParts(
        string $websiteCode,
        string $storeCode = '',
        string $channelCode = '',
        string $scopeKind = '',
        string $storeMode = ScopeIdentity::MODE_NORMAL,
    ): array
    {
        $websiteCode = strtolower(trim($websiteCode));
        $storeCode = strtolower(trim($storeCode));
        $channelCode = strtolower(trim($channelCode));
        $scopeKind = strtolower(trim($scopeKind));
        $storeMode = strtolower(trim($storeMode));
        if ($scopeKind !== '' && !\in_array($scopeKind, ScopeIdentity::KINDS, true)) {
            throw new \InvalidArgumentException('system_config_scope_kind_invalid');
        }

        // 空 website = Global（配置中心「Global」选项）
        if ($websiteCode === '') {
            if (($scopeKind !== '' && $scopeKind !== ScopeIdentity::KIND_GLOBAL)
                || $storeCode !== ''
                || $channelCode !== ''
            ) {
                throw new \InvalidArgumentException('system_config_global_rejects_store_channel');
            }

            return $this->pack(ScopeIdentity::global(), SystemConfig::SCOPE_GLOBAL);
        }

        if ($scopeKind === ScopeIdentity::KIND_GLOBAL) {
            throw new \InvalidArgumentException('system_config_global_rejects_website');
        }

        if ($scopeKind === ScopeIdentity::KIND_WEBSITE || ($scopeKind === '' && ($storeCode === '' || $storeCode === 'default'))) {
            if (($scopeKind === ScopeIdentity::KIND_WEBSITE && $storeCode !== '') || $channelCode !== '') {
                throw new \InvalidArgumentException('system_config_website_rejects_store_channel');
            }
            $identity = ScopeIdentity::website($this->resolveWebsiteId($websiteCode), $websiteCode);

            $identity = $this->resolveAuthoritativeIdentity($identity);

            return $this->pack($identity, $this->resolver->toStorageScope($identity));
        }

        if ($scopeKind === ScopeIdentity::KIND_STORE || ($scopeKind === '' && ($channelCode === '' || $channelCode === 'default'))) {
            if ($scopeKind === ScopeIdentity::KIND_STORE && ($storeCode === '' || $channelCode !== '')) {
                throw new \InvalidArgumentException('system_config_store_scope_claims_invalid');
            }
            $identity = ScopeIdentity::store(
                $this->resolveWebsiteId($websiteCode),
                $websiteCode,
                $storeCode === '' ? 'default' : $storeCode,
                $storeMode,
            );

            $identity = $this->resolveAuthoritativeIdentity($identity);

            return $this->pack($identity, $this->resolver->toStorageScope($identity));
        }

        if ($storeCode === '' || $channelCode === '') {
            throw new \InvalidArgumentException('system_config_channel_scope_claims_invalid');
        }

        $identity = ScopeIdentity::channel(
            $this->resolveWebsiteId($websiteCode),
            $websiteCode,
            $storeCode,
            $channelCode,
            $storeMode,
        );

        $identity = $this->resolveAuthoritativeIdentity($identity);

        return $this->pack($identity, $this->resolver->toStorageScope($identity));
    }

    /**
     * @param array{kind:string,website_code:string,store_code:string,channel_code:string,store_mode:string,storage_scope:string,identity:ScopeIdentity} $target
     */
    public function rememberSession(array $target): void
    {
        $this->uiState->write(self::SESSION_KEY, [
            'kind' => $target['kind'],
            'website_code' => $target['website_code'],
            'store_code' => $target['store_code'],
            'channel_code' => $target['channel_code'],
            'store_mode' => $target['store_mode'],
            'storage_scope' => $target['storage_scope'],
        ]);
    }

    /**
     * @return array{kind:string,website_code:string,store_code:string,channel_code:string,store_mode:string,storage_scope:string,identity:ScopeIdentity}|null
     */
    public function readSessionScope(): ?array
    {
        $raw = $this->uiState->read(self::SESSION_KEY);
        if (!\is_array($raw)) {
            return null;
        }
        $storage = trim((string)($raw['storage_scope'] ?? ''));
        if ($storage === '') {
            return null;
        }
        try {
            $this->resolver->assertWritableRawScope($storage);
        } catch (\Throwable) {
            return null;
        }
        $normalized = $this->normalizeStorage($storage);
        $storeMode = strtolower(trim((string)($raw['store_mode'] ?? ScopeIdentity::MODE_NORMAL)));
        $identity = $this->resolveAuthoritativeIdentity(
            $this->withStoreMode(
                $this->resolver->fromStorageScope($normalized) ?? ScopeIdentity::global(),
                $storeMode,
            ),
        );

        return $this->pack($identity, $this->resolver->toStorageScope($identity));
    }

    /**
     * @return list<array{code:string,name:string,website_id:int,stores:list<array{code:string,name:string,channels:list<array{code:string,name:string}>}>}>
     */
    public function catalogOptions(): array
    {
        return $this->identityCatalog->options();
    }

    /**
     * Same-origin check for backend writes (TEST-SEC-07).
     */
    public function assertSameOrigin(?string $origin, ?string $host, ?string $referer = null): void
    {
        $host = strtolower(trim((string)$host));
        if ($host === '') {
            throw new \RuntimeException('system_config_origin_host_missing');
        }
        $expectedHost = $host;
        if (str_contains($expectedHost, ':')) {
            $expectedHost = explode(':', $expectedHost, 2)[0];
        }

        $origin = trim((string)$origin);
        if ($origin !== '') {
            $parts = parse_url($origin);
            $originHost = strtolower((string)($parts['host'] ?? ''));
            if ($originHost === '' || $originHost !== $expectedHost) {
                throw new \RuntimeException('system_config_origin_mismatch');
            }

            return;
        }

        // Origin 为空时退回 Referer（部分同站表单）
        $referer = trim((string)$referer);
        if ($referer === '') {
            throw new \RuntimeException('system_config_origin_required');
        }
        $parts = parse_url($referer);
        $refHost = strtolower((string)($parts['host'] ?? ''));
        if ($refHost === '' || $refHost !== $expectedHost) {
            throw new \RuntimeException('system_config_origin_mismatch');
        }
    }

    private function normalizeStorage(string $scope): string
    {
        $scope = strtolower(trim($scope));
        if ($scope === '' || $scope === 'default') {
            return SystemConfig::SCOPE_GLOBAL;
        }
        $parts = array_values(array_filter(explode('.', $scope), static fn(string $p): bool => $p !== ''));
        while (count($parts) < 3) {
            $parts[] = 'default';
        }

        return implode('.', array_slice($parts, 0, 3));
    }

    private function resolveAuthoritativeIdentity(ScopeIdentity $identity): ScopeIdentity
    {
        if (!$identity->isGlobal() && $identity->websiteId === 0) {
            $identity = match ($identity->scopeKind) {
                ScopeIdentity::KIND_WEBSITE => ScopeIdentity::website(
                    $this->resolveWebsiteId((string)$identity->websiteCode),
                    (string)$identity->websiteCode,
                    $identity->contextVersion,
                ),
                ScopeIdentity::KIND_STORE => ScopeIdentity::store(
                    $this->resolveWebsiteId((string)$identity->websiteCode),
                    (string)$identity->websiteCode,
                    (string)$identity->storeCode,
                    (string)$identity->storeMode,
                    $identity->contextVersion,
                ),
                ScopeIdentity::KIND_CHANNEL => ScopeIdentity::channel(
                    $this->resolveWebsiteId((string)$identity->websiteCode),
                    (string)$identity->websiteCode,
                    (string)$identity->storeCode,
                    (string)$identity->channelCode,
                    (string)$identity->storeMode,
                    $identity->contextVersion,
                ),
                default => $identity,
            };
        }

        return $this->identityCatalog->authoritativeIdentity($identity);
    }

    private function withStoreMode(ScopeIdentity $identity, string $storeMode): ScopeIdentity
    {
        if (!\in_array($identity->scopeKind, [ScopeIdentity::KIND_STORE, ScopeIdentity::KIND_CHANNEL], true)) {
            return $identity;
        }

        return $identity->scopeKind === ScopeIdentity::KIND_STORE
            ? ScopeIdentity::store(
                $identity->websiteId,
                (string)$identity->websiteCode,
                (string)$identity->storeCode,
                $storeMode,
                $identity->contextVersion,
            )
            : ScopeIdentity::channel(
                $identity->websiteId,
                (string)$identity->websiteCode,
                (string)$identity->storeCode,
                (string)$identity->channelCode,
                $storeMode,
                $identity->contextVersion,
            );
    }

    private function resolveWebsiteId(string $websiteCode): int
    {
        return $this->identityCatalog->websiteIdForCode($websiteCode);
    }

    /**
     * @return array{kind:string,website_code:string,store_code:string,channel_code:string,store_mode:string,storage_scope:string,identity:ScopeIdentity}
     */
    private function pack(ScopeIdentity $identity, string $storageScope): array
    {
        $kind = (string)$identity->scopeKind;
        $website = $kind === ScopeIdentity::KIND_GLOBAL
            ? ''
            : strtolower((string)($identity->websiteCode ?: 'default'));

        return [
            'kind' => $kind,
            'website_code' => $website,
            'store_code' => strtolower((string)($identity->storeCode ?: '')),
            'channel_code' => strtolower((string)($identity->channelCode ?: '')),
            'store_mode' => strtolower((string)($identity->storeMode ?: ScopeIdentity::MODE_NORMAL)),
            'storage_scope' => $storageScope,
            'identity' => $identity,
        ];
    }
}
