<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;

/**
 * Payment 后台对象 Scope 解析。
 *
 * 已存在交易只能传入持久化 scope；管理类读取/写入必须传入显式 target_scope/scope。
 * `default.default.default` 表示 website_id=0 的系统默认站，不表示 Global。
 * 配置中心存储哨兵（`__website__` / `__store__` / `__channel__`）按 SystemConfig 语义还原，
 * 禁止把哨兵段交给 ScopeIdentity 当 store_code / channel_code。
 */
class PaymentObjectScopeService
{
    private const WEBSITE_SENTINEL = '__website__';
    private const STORE_SENTINEL = '__store__';
    private const CHANNEL_SENTINEL = '__channel__';
    private readonly ?\Closure $websiteIdResolver;

    public function __construct(?callable $websiteIdResolver = null)
    {
        $this->websiteIdResolver = $websiteIdResolver === null
            ? null
            : \Closure::fromCallable($websiteIdResolver);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function fromExplicitTarget(array $params): ScopeIdentity
    {
        if (!\array_key_exists('target_scope', $params) && !\array_key_exists('scope', $params)) {
            throw new \InvalidArgumentException('payment_admin_requires_explicit_target_scope');
        }

        return $this->fromPersistedScope(
            (string)($params['target_scope'] ?? $params['scope'] ?? ''),
            true,
        );
    }

    public function fromPersistedScope(string $scope, bool $allowExplicitGlobal = false): ScopeIdentity
    {
        $scope = \strtolower(\trim($scope));
        if ($allowExplicitGlobal && $scope === 'global') {
            return ScopeIdentity::global();
        }
        if ($scope === '' || \preg_match('/^[a-z0-9_-]+(?:\.[a-z0-9_-]+){2}$/D', $scope) !== 1) {
            throw new \InvalidArgumentException('payment_scope_identity_invalid');
        }

        [$website, $store, $channel] = \explode('.', $scope, 3);
        $websiteId = $this->resolveWebsiteId($website);
        if ($store === self::WEBSITE_SENTINEL && $channel === 'default') {
            return ScopeIdentity::website($websiteId, $website);
        }
        if ($store === self::STORE_SENTINEL && $channel === 'default') {
            return ScopeIdentity::store(
                $websiteId,
                $website,
                'default',
                ScopeIdentity::MODE_NORMAL,
            );
        }
        if ($store === self::STORE_SENTINEL && $channel === self::CHANNEL_SENTINEL) {
            return ScopeIdentity::channel(
                $websiteId,
                $website,
                'default',
                'default',
                ScopeIdentity::MODE_NORMAL,
            );
        }
        if ($channel === self::CHANNEL_SENTINEL && $store !== 'default' && $store !== self::WEBSITE_SENTINEL) {
            return ScopeIdentity::channel(
                $websiteId,
                $website,
                $store,
                'default',
                ScopeIdentity::MODE_NORMAL,
            );
        }
        if ($store === 'default' && $channel === 'default') {
            return ScopeIdentity::website($websiteId, $website);
        }
        if ($store === 'default' || \str_starts_with($store, '__') || \str_starts_with($channel, '__')) {
            throw new \InvalidArgumentException(
                $store === 'default' ? 'payment_channel_requires_store' : 'payment_scope_identity_invalid',
            );
        }
        if ($channel === 'default') {
            return ScopeIdentity::store(
                $websiteId,
                $website,
                $store,
                ScopeIdentity::MODE_NORMAL,
            );
        }

        return ScopeIdentity::channel(
            $websiteId,
            $website,
            $store,
            $channel,
            ScopeIdentity::MODE_NORMAL,
        );
    }

    private function resolveWebsiteId(string $websiteCode): int
    {
        if ($this->websiteIdResolver !== null) {
            $websiteId = (int)($this->websiteIdResolver)($websiteCode);
            if ($websiteId < 0) {
                throw new \InvalidArgumentException('payment_website_scope_not_found');
            }

            return $websiteId;
        }
        if ($websiteCode === 'default') {
            return 0;
        }
        if (!\class_exists(\Weline\Websites\Model\Website::class)) {
            throw new \InvalidArgumentException('payment_website_scope_not_found');
        }

        /** @var \Weline\Websites\Model\Website $website */
        $website = ObjectManager::getInstance(\Weline\Websites\Model\Website::class);
        $website->load(\Weline\Websites\Model\Website::schema_fields_CODE, $websiteCode);
        if (!$website->getId()) {
            throw new \InvalidArgumentException('payment_website_scope_not_found');
        }

        return (int)$website->getId();
    }
}
