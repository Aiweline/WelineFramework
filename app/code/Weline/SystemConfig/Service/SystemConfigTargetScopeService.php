<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
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

    private readonly ?\Closure $websiteIdResolver;

    public function __construct(
        private readonly SystemConfigScopeResolver $resolver,
        ?callable $websiteIdResolver = null,
    ) {
        $this->websiteIdResolver = $websiteIdResolver === null
            ? null
            : \Closure::fromCallable($websiteIdResolver);
    }

    /**
     * @param array<string, mixed> $input website_code/store_code/channel_code 或 target_scope/scope
     * @return array{
     *   kind:string,
     *   website_code:string,
     *   store_code:string,
     *   channel_code:string,
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
        $hasSegmentFields = \array_key_exists('website_code', $input)
            || \array_key_exists('target_website', $input)
            || \array_key_exists('store_code', $input)
            || \array_key_exists('channel_code', $input);

        if ($hasSegmentFields) {
            return $this->fromParts($website, $store, $channel);
        }

        if ($website !== '' || $store !== '' || $channel !== '') {
            return $this->fromParts($website, $store, $channel);
        }

        if ($explicit !== '') {
            $this->resolver->assertWritableRawScope($explicit);
            $normalized = $this->normalizeStorage($explicit);
            $identity = $this->resolveWebsiteIdentity(
                $this->resolver->fromStorageScope($normalized) ?? ScopeIdentity::global(),
            );

            return $this->pack($identity, $normalized);
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
     * @return array{kind:string,website_code:string,store_code:string,channel_code:string,storage_scope:string,identity:ScopeIdentity}
     */
    public function fromParts(string $websiteCode, string $storeCode = '', string $channelCode = ''): array
    {
        $websiteCode = strtolower(trim($websiteCode));
        $storeCode = strtolower(trim($storeCode));
        $channelCode = strtolower(trim($channelCode));

        // 空 website = Global（配置中心「Global」选项）
        if ($websiteCode === '') {
            if ($storeCode !== '' || ($channelCode !== '' && $channelCode !== 'default')) {
                throw new \InvalidArgumentException('system_config_global_rejects_store_channel');
            }

            return $this->pack(ScopeIdentity::global(), SystemConfig::SCOPE_GLOBAL);
        }

        if ($storeCode === '' || $storeCode === 'default') {
            if ($channelCode !== '' && $channelCode !== 'default') {
                throw new \InvalidArgumentException('system_config_channel_requires_store');
            }
            $identity = ScopeIdentity::website($this->resolveWebsiteId($websiteCode), $websiteCode);

            return $this->pack($identity, $this->resolver->toStorageScope($identity));
        }

        if ($channelCode === '' || $channelCode === 'default') {
            $identity = ScopeIdentity::store(
                $this->resolveWebsiteId($websiteCode),
                $websiteCode,
                $storeCode,
                ScopeIdentity::MODE_NORMAL,
            );

            return $this->pack($identity, $this->resolver->toStorageScope($identity));
        }

        $identity = ScopeIdentity::channel(
            $this->resolveWebsiteId($websiteCode),
            $websiteCode,
            $storeCode,
            $channelCode,
            ScopeIdentity::MODE_NORMAL,
        );

        return $this->pack($identity, $this->resolver->toStorageScope($identity));
    }

    /**
     * @param array{kind:string,website_code:string,store_code:string,channel_code:string,storage_scope:string,identity:ScopeIdentity} $target
     */
    public function rememberSession(array $target): void
    {
        if (!isset($_SESSION) || !\is_array($_SESSION)) {
            return;
        }
        $_SESSION[self::SESSION_KEY] = [
            'kind' => $target['kind'],
            'website_code' => $target['website_code'],
            'store_code' => $target['store_code'],
            'channel_code' => $target['channel_code'],
            'storage_scope' => $target['storage_scope'],
        ];
    }

    /**
     * @return array{kind:string,website_code:string,store_code:string,channel_code:string,storage_scope:string,identity:ScopeIdentity}|null
     */
    public function readSessionScope(): ?array
    {
        $raw = $_SESSION[self::SESSION_KEY] ?? null;
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
        $identity = $this->resolveWebsiteIdentity(
            $this->resolver->fromStorageScope($normalized) ?? ScopeIdentity::global(),
        );

        return $this->pack($identity, $normalized);
    }

    /**
     * @return list<array{code:string,name:string,website_id:int,stores:list<array{code:string,name:string,channels:list<array{code:string,name:string}>}>}>
     */
    public function catalogOptions(): array
    {
        try {
            if (!\class_exists(\Weline\Websites\Model\Website::class)) {
                return [];
            }
            /** @var \Weline\Websites\Model\Website $websiteModel */
            $websiteModel = ObjectManager::getInstance(\Weline\Websites\Model\Website::class);
            $websites = $websiteModel->clear()->reset()->select()->fetchArray();
            if (!\is_array($websites)) {
                return [];
            }
            $out = [];
            foreach ($websites as $website) {
                if (!\is_array($website)) {
                    continue;
                }
                $code = strtolower(trim((string)($website[\Weline\Websites\Model\Website::schema_fields_CODE] ?? '')));
                $websiteId = (int)($website[\Weline\Websites\Model\Website::schema_fields_ID] ?? 0);
                if ($code === '' || $websiteId < 0) {
                    continue;
                }
                $stores = [];
                if (\class_exists(\Weline\Websites\Service\WebsiteStoreChannelDirectory::class)) {
                    /** @var \Weline\Websites\Service\WebsiteStoreChannelDirectory $dir */
                    $dir = ObjectManager::getInstance(\Weline\Websites\Service\WebsiteStoreChannelDirectory::class);
                    foreach ($dir->forWebsite($websiteId) as $store) {
                        $storeCode = strtolower(trim((string)($store['code'] ?? '')));
                        if ($storeCode === '') {
                            continue;
                        }
                        $channels = [];
                        foreach (($store['channels'] ?? []) as $channel) {
                            if (!\is_array($channel)) {
                                continue;
                            }
                            $channelCode = strtolower(trim((string)($channel['code'] ?? '')));
                            if ($channelCode === '' || $channelCode === 'default') {
                                continue;
                            }
                            $channels[] = [
                                'code' => $channelCode,
                                'name' => (string)($channel['name'] ?? $channelCode),
                            ];
                        }
                        $stores[] = [
                            'code' => $storeCode,
                            'name' => (string)($store['name'] ?? $storeCode),
                            'channels' => $channels,
                        ];
                    }
                }
                $out[] = [
                    'code' => $code,
                    'name' => (string)($website[\Weline\Websites\Model\Website::schema_fields_NAME] ?? $code),
                    'website_id' => $websiteId,
                    'stores' => $stores,
                ];
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
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

    private function resolveWebsiteIdentity(ScopeIdentity $identity): ScopeIdentity
    {
        if ($identity->isGlobal()) {
            return $identity;
        }
        $websiteCode = (string)$identity->websiteCode;
        $websiteId = $this->resolveWebsiteId($websiteCode);

        return match ($identity->scopeKind) {
            ScopeIdentity::KIND_WEBSITE => ScopeIdentity::website($websiteId, $websiteCode),
            ScopeIdentity::KIND_STORE => ScopeIdentity::store(
                $websiteId,
                $websiteCode,
                (string)$identity->storeCode,
                (string)$identity->storeMode,
            ),
            ScopeIdentity::KIND_CHANNEL => ScopeIdentity::channel(
                $websiteId,
                $websiteCode,
                (string)$identity->storeCode,
                (string)$identity->channelCode,
                (string)$identity->storeMode,
            ),
            default => throw new \InvalidArgumentException('system_config_scope_identity_invalid'),
        };
    }

    private function resolveWebsiteId(string $websiteCode): int
    {
        if ($this->websiteIdResolver !== null) {
            $resolved = ($this->websiteIdResolver)($websiteCode);
            if (\is_int($resolved) && $resolved >= 0) {
                return $resolved;
            }
            throw new \InvalidArgumentException('system_config_website_scope_not_found');
        }
        if (!\class_exists(\Weline\Websites\Model\Website::class)) {
            throw new \RuntimeException('system_config_website_scope_provider_unavailable');
        }

        /** @var \Weline\Websites\Model\Website $website */
        $website = ObjectManager::getInstance(\Weline\Websites\Model\Website::class);
        $rows = $website->clear()->reset()
            ->where(\Weline\Websites\Model\Website::schema_fields_CODE, $websiteCode)
            ->select()
            ->fetchArray();
        $row = \is_array($rows) ? ($rows[0] ?? null) : null;
        if (!\is_array($row)) {
            throw new \InvalidArgumentException('system_config_website_scope_not_found');
        }
        $websiteId = $row[\Weline\Websites\Model\Website::schema_fields_ID] ?? null;
        if (!\is_int($websiteId)
            && !(\is_string($websiteId) && \preg_match('/^(0|[1-9][0-9]*)$/D', $websiteId) === 1)
        ) {
            throw new \InvalidArgumentException('system_config_website_scope_not_found');
        }

        return (int)$websiteId;
    }

    /**
     * @return array{kind:string,website_code:string,store_code:string,channel_code:string,storage_scope:string,identity:ScopeIdentity}
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
            'storage_scope' => $storageScope,
            'identity' => $identity,
        ];
    }
}
