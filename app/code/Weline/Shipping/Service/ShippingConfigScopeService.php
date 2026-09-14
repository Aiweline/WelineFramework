<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Shipping\Model\ShippingService;
use Weline\SystemConfig\Service\SystemConfigTargetScopeService;
use Weline\Websites\Model\SalesChannel;
use Weline\Websites\Model\Store;

/**
 * 配送业务配置作用范围：website|store|channel（对齐禁运/可售，非 Global KV）。
 *
 * 后台：显式 target_scope → scope_type + scope_id。
 * 前台报价：channel → store → website 取最近「有启用航线」的一层。
 */
final class ShippingConfigScopeService
{
    public const SCOPE_WEBSITE = 'website';
    public const SCOPE_STORE = 'store';
    public const SCOPE_CHANNEL = 'channel';

    public function __construct(
        private readonly ObjectManager $objectManager,
        private readonly ?SystemConfigTargetScopeService $targetScopeService = null,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{
     *   scope_type:string,
     *   scope_id:int,
     *   storage_scope:string,
     *   website_code:string,
     *   store_code:string,
     *   channel_code:string,
     *   kind:string
     * }
     */
    public function resolveAdminTarget(array $input, bool $allowSessionFallback = false): array
    {
        $svc = $this->targetScopeService
            ?? $this->objectManager->getInstance(SystemConfigTargetScopeService::class);
        $resolved = $svc->resolveFromInput([
            'target_scope' => (string)($input['target_scope'] ?? $input['scope'] ?? ''),
            'scope' => (string)($input['scope'] ?? ''),
            'website_code' => (string)($input['website_code'] ?? ''),
            'store_code' => (string)($input['store_code'] ?? ''),
            'channel_code' => (string)($input['channel_code'] ?? ''),
            'scope_kind' => (string)($input['scope_kind'] ?? $input['kind'] ?? ''),
        ], $allowSessionFallback);

        return $this->fromResolvedTarget($resolved);
    }

    /**
     * @param array{kind?:string,storage_scope?:string,website_code?:string,store_code?:string,channel_code?:string,identity?:ScopeIdentity} $resolved
     * @return array{scope_type:string,scope_id:int,storage_scope:string,website_code:string,store_code:string,channel_code:string,kind:string}
     */
    public function fromResolvedTarget(array $resolved): array
    {
        $kind = strtolower(trim((string)($resolved['kind'] ?? ScopeIdentity::KIND_WEBSITE)));
        $storage = strtolower(trim((string)($resolved['storage_scope'] ?? 'default.default.default')));
        $websiteCode = strtolower(trim((string)($resolved['website_code'] ?? 'default')));
        $storeCode = strtolower(trim((string)($resolved['store_code'] ?? '')));
        $channelCode = strtolower(trim((string)($resolved['channel_code'] ?? '')));
        $identity = $resolved['identity'] ?? null;

        if ($kind === ScopeIdentity::KIND_GLOBAL || $kind === '') {
            // 业务配送不允许悬空 Global：落到系统默认站 website_id=0
            return [
                'scope_type' => self::SCOPE_WEBSITE,
                'scope_id' => 0,
                'storage_scope' => $storage !== '' ? $storage : 'default.default.default',
                'website_code' => $websiteCode !== '' ? $websiteCode : 'default',
                'store_code' => '',
                'channel_code' => '',
                'kind' => ScopeIdentity::KIND_WEBSITE,
            ];
        }

        $websiteId = $identity instanceof ScopeIdentity
            ? (int)($identity->websiteId ?? 0)
            : 0;

        if ($kind === ScopeIdentity::KIND_CHANNEL) {
            $storeId = $this->resolveStoreId($websiteId, $storeCode !== '' ? $storeCode : 'default');
            $channelId = $this->resolveChannelId($storeId, $channelCode !== '' ? $channelCode : 'default');

            return [
                'scope_type' => self::SCOPE_CHANNEL,
                'scope_id' => $channelId,
                'storage_scope' => $storage,
                'website_code' => $websiteCode,
                'store_code' => $storeCode,
                'channel_code' => $channelCode,
                'kind' => $kind,
            ];
        }

        if ($kind === ScopeIdentity::KIND_STORE) {
            $storeId = $this->resolveStoreId($websiteId, $storeCode !== '' ? $storeCode : 'default');

            return [
                'scope_type' => self::SCOPE_STORE,
                'scope_id' => $storeId,
                'storage_scope' => $storage,
                'website_code' => $websiteCode,
                'store_code' => $storeCode,
                'channel_code' => '',
                'kind' => $kind,
            ];
        }

        return [
            'scope_type' => self::SCOPE_WEBSITE,
            'scope_id' => $websiteId,
            'storage_scope' => $storage,
            'website_code' => $websiteCode,
            'store_code' => '',
            'channel_code' => '',
            'kind' => ScopeIdentity::KIND_WEBSITE,
        ];
    }

    /**
     * @param array{website_id?:int,store_id?:int,channel_id?:int}|null $context
     * @return list<array{scope_type:string,scope_id:int}>
     */
    public function quoteLayerChain(?array $context = null): array
    {
        $websiteId = isset($context['website_id'])
            ? max(0, (int)$context['website_id'])
            : max(0, RequestContext::getWelineWebsiteId());
        $storeId = isset($context['store_id'])
            ? max(0, (int)$context['store_id'])
            : max(0, RequestContext::getWelineStoreId());
        $channelId = isset($context['channel_id'])
            ? max(0, (int)$context['channel_id'])
            : max(0, RequestContext::getWelineChannelId());

        $chain = [];
        if ($channelId > 0) {
            $chain[] = ['scope_type' => self::SCOPE_CHANNEL, 'scope_id' => $channelId];
        }
        if ($storeId > 0) {
            $chain[] = ['scope_type' => self::SCOPE_STORE, 'scope_id' => $storeId];
        }
        $chain[] = ['scope_type' => self::SCOPE_WEBSITE, 'scope_id' => $websiteId];

        return $chain;
    }

    /**
     * 最近一层「有启用配送航线」的配置；请求链都没有时回退 website:0 种子层。
     *
     * 系统种子航线默认落在 website:0；店面 website_id>0 且未复制航线时必须能吃到该层，
     * 否则结账/Express 会得到空配送并误报 shipping_profile_conflict。
     *
     * @param array{website_id?:int,store_id?:int,channel_id?:int}|null $context
     * @return array{scope_type:string,scope_id:int}
     */
    public function resolveNearestServiceLayer(?array $context = null): array
    {
        $chain = $this->quoteLayerChain($context);
        $seed = ['scope_type' => self::SCOPE_WEBSITE, 'scope_id' => 0];
        $last = $chain[array_key_last($chain)] ?? null;
        if (
            $last === null
            || (string)($last['scope_type'] ?? '') !== self::SCOPE_WEBSITE
            || (int)($last['scope_id'] ?? -1) !== 0
        ) {
            $chain[] = $seed;
        }
        foreach ($chain as $layer) {
            if ($this->countActiveServices($layer['scope_type'], $layer['scope_id']) > 0) {
                return $layer;
            }
        }

        return $seed;
    }

    public function countActiveServices(string $scopeType, int $scopeId): int
    {
        try {
            /** @var ShippingService $model */
            $model = $this->objectManager->getInstance(ShippingService::class, [], false);
            $items = $model->reset()
                ->where(ShippingService::schema_fields_SCOPE_TYPE, $scopeType)
                ->where(ShippingService::schema_fields_SCOPE_ID, $scopeId)
                ->where(ShippingService::schema_fields_IS_ACTIVE, 1)
                ->select()
                ->fetch()
                ->getItems();

            return is_array($items) ? count($items) : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @param object $query AbstractModel query builder
     * @param array{scope_type:string,scope_id:int} $layer
     * @param string $typeField
     * @param string $idField
     */
    public function applyScopeWhere(
        object $query,
        array $layer,
        string $typeField = 'scope_type',
        string $idField = 'scope_id',
    ): object {
        return $query
            ->where($typeField, (string)$layer['scope_type'])
            ->where($idField, (int)$layer['scope_id']);
    }

    private function resolveStoreId(int $websiteId, string $storeCode): int
    {
        $code = strtolower(trim($storeCode));
        if ($code === '') {
            $code = 'default';
        }
        try {
            /** @var Store $probe */
            $probe = $this->objectManager->getInstance(Store::class, [], false);
            $items = $probe->reset()
                ->where(Store::schema_fields_WEBSITE_ID, $websiteId)
                ->where(Store::schema_fields_CODE, $code)
                ->select()
                ->fetch()
                ->getItems();
            $first = is_array($items) ? ($items[0] ?? null) : null;
            if ($first instanceof Store) {
                return (int)$first->getId();
            }
        } catch (\Throwable) {
        }

        return 0;
    }

    private function resolveChannelId(int $storeId, string $channelCode): int
    {
        $code = strtolower(trim($channelCode));
        if ($code === '') {
            $code = 'default';
        }
        if ($storeId <= 0) {
            return 0;
        }
        try {
            /** @var SalesChannel $channel */
            $channel = $this->objectManager->getInstance(SalesChannel::class, [], false);
            $items = $channel->reset()
                ->where(SalesChannel::schema_fields_STORE_ID, $storeId)
                ->where(SalesChannel::schema_fields_CODE, $code)
                ->select()
                ->fetch()
                ->getItems();
            $first = is_array($items) ? ($items[0] ?? null) : null;
            if ($first instanceof SalesChannel) {
                return (int)$first->getId();
            }
        } catch (\Throwable) {
        }

        return 0;
    }
}
