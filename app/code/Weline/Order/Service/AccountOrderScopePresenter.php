<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\Order;
use Weline\Websites\Api\Catalog\SalesChannelCatalogInterface;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;
use Weline\Websites\Model\SalesChannel;
use Weline\Websites\Model\Store;
use Weline\Websites\Service\ScopeDisplayTypeRegistry;
use Weline\Websites\Service\ScopeDisplayTypeResolver;

/**
 * 账户订单范围标签：活目录优先，scope_snapshot_json 回退。
 * 不硬编码 B2B 业务路径；display_type 经 Websites 解析。
 */
final class AccountOrderScopePresenter
{
    /** @var array<int, array{code:string,name:string}|null> */
    private array $storeCache = [];

    /** @var array<int, array{code:string,name:string,is_default:bool}|null> */
    private array $channelCache = [];

    public function __construct(
        private readonly ?StoreCatalogInterface $stores = null,
        private readonly ?SalesChannelCatalogInterface $channels = null,
        private readonly ?ScopeDisplayTypeResolver $displayTypes = null,
        private readonly ?ScopeDisplayTypeRegistry $displayTypeRegistry = null,
    ) {
    }

    /**
     * @param array<string, mixed> $orderRow Order 行（含 store_id / website_id / scope_snapshot_json）
     * @return array{
     *   store_id:int,
     *   store_code:string,
     *   store_name:string,
     *   channel_id:int,
     *   channel_code:string,
     *   channel_name:string,
     *   display_type:string,
     *   display_type_label:string,
     *   label:string
     * }
     */
    public function presentFromOrderRow(array $orderRow): array
    {
        $snapshot = $this->decodeSnapshot(
            (string)($orderRow[Order::schema_fields_SCOPE_SNAPSHOT_JSON] ?? $orderRow['scope_snapshot_json'] ?? '')
        );

        $websiteId = (int)($orderRow[Order::schema_fields_WEBSITE_ID] ?? $orderRow['website_id'] ?? $snapshot['website_id'] ?? 0);
        $storeId = (int)($orderRow[Order::schema_fields_STORE_ID] ?? $orderRow['store_id'] ?? $snapshot['store_id'] ?? 0);
        $channelId = (int)($snapshot['channel_id'] ?? $orderRow['channel_id'] ?? 0);

        $store = $this->resolveStore($storeId);
        $storeCode = $store['code'] !== ''
            ? $store['code']
            : trim((string)($snapshot['store_code'] ?? ''));
        $storeName = $store['name'] !== ''
            ? $store['name']
            : trim((string)($snapshot['store_name'] ?? $snapshot['store_code'] ?? ''));
        if ($storeName === '' && $storeCode !== '') {
            $storeName = $storeCode;
        }
        if ($storeName === '') {
            $storeName = $storeId > 0
                ? (string)\__('店铺 #%{1}', [$storeId])
                : (string)\__('默认店铺');
        }

        $channel = $this->resolveChannel($channelId);
        $channelCode = $channel['code'] !== ''
            ? $channel['code']
            : trim((string)($snapshot['channel_code'] ?? ''));
        $channelName = $channel['name'] !== ''
            ? $channel['name']
            : trim((string)($snapshot['channel_name'] ?? ''));
        $channelIsDefault = $channel['is_default']
            || $channelCode === ''
            || $channelCode === SalesChannel::CODE_DEFAULT
            || $channelId <= 0;

        $displayType = trim((string)($snapshot['display_type'] ?? ''));
        if ($displayType === '') {
            $displayType = $this->resolveDisplayType($websiteId, $storeId, $channelId);
        }
        $displayTypeLabel = $this->displayTypeLabel($displayType);

        $labelParts = [$storeName];
        if (!$channelIsDefault && $channelName !== '') {
            $labelParts[] = $channelName;
        }
        if ($displayTypeLabel !== '') {
            $labelParts[] = $displayTypeLabel;
        }

        return [
            'store_id' => $storeId,
            'store_code' => $storeCode !== '' ? $storeCode : ($storeId > 0 ? (string)$storeId : Store::CODE_DEFAULT),
            'store_name' => $storeName,
            'channel_id' => $channelId,
            'channel_code' => $channelCode,
            'channel_name' => $channelName,
            'display_type' => $displayType,
            'display_type_label' => $displayTypeLabel,
            'label' => implode(' · ', $labelParts),
        ];
    }

    /**
     * @param array<string, mixed> $detail AccountOrderDetailResolver / OrderFacade 详情
     * @return array<string, mixed>
     */
    public function presentFromDetail(array $detail): array
    {
        $scope = is_array($detail['scope'] ?? null) ? $detail['scope'] : [];
        $row = [
            Order::schema_fields_WEBSITE_ID => (int)($detail['website_id'] ?? $scope['website_id'] ?? 0),
            Order::schema_fields_STORE_ID => (int)($detail['store_id'] ?? $scope['store_id'] ?? 0),
            Order::schema_fields_SCOPE_SNAPSHOT_JSON => $scope !== []
                ? json_encode($scope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : (string)($detail['scope_snapshot_json'] ?? ''),
            'channel_id' => (int)($scope['channel_id'] ?? $detail['channel_id'] ?? 0),
        ];

        return $this->presentFromOrderRow($row);
    }

    /**
     * @return array{code:string,name:string}
     */
    private function resolveStore(int $storeId): array
    {
        if (array_key_exists($storeId, $this->storeCache)) {
            $cached = $this->storeCache[$storeId];

            return $cached ?? ['code' => '', 'name' => ''];
        }

        $empty = ['code' => '', 'name' => ''];
        $catalog = $this->stores();
        if ($catalog === null) {
            $this->storeCache[$storeId] = null;

            return $empty;
        }
        try {
            $summary = $catalog->byId($storeId);
            if ($summary === null) {
                $this->storeCache[$storeId] = null;

                return $empty;
            }
            $mapped = [
                'code' => trim($summary->code),
                'name' => trim($summary->name),
            ];
            $this->storeCache[$storeId] = $mapped;

            return $mapped;
        } catch (\Throwable) {
            $this->storeCache[$storeId] = null;

            return $empty;
        }
    }

    /**
     * @return array{code:string,name:string,is_default:bool}
     */
    private function resolveChannel(int $channelId): array
    {
        // id<=0 → 默认渠道；目录未命中时 is_default=false，交给 snapshot 判定。
        if ($channelId <= 0) {
            return ['code' => '', 'name' => '', 'is_default' => true];
        }
        $miss = ['code' => '', 'name' => '', 'is_default' => false];
        if (array_key_exists($channelId, $this->channelCache)) {
            $cached = $this->channelCache[$channelId];

            return $cached ?? $miss;
        }

        $catalog = $this->channels();
        if ($catalog === null) {
            $this->channelCache[$channelId] = null;

            return $miss;
        }
        try {
            $summary = $catalog->byId($channelId);
            if ($summary === null) {
                $this->channelCache[$channelId] = null;

                return $miss;
            }
            $mapped = [
                'code' => trim($summary->code),
                'name' => trim($summary->name),
                'is_default' => $summary->isDefault || trim($summary->code) === SalesChannel::CODE_DEFAULT,
            ];
            $this->channelCache[$channelId] = $mapped;

            return $mapped;
        } catch (\Throwable) {
            $this->channelCache[$channelId] = null;

            return $miss;
        }
    }

    private function resolveDisplayType(int $websiteId, int $storeId, int $channelId): string
    {
        $resolver = $this->displayTypes();
        if ($resolver === null) {
            return '';
        }
        try {
            return trim($resolver->resolveForScope($websiteId, max(0, $storeId), max(0, $channelId)));
        } catch (\Throwable) {
            return '';
        }
    }

    private function displayTypeLabel(string $code): string
    {
        $code = strtolower(trim($code));
        if ($code === '') {
            return '';
        }
        $registry = $this->displayTypeRegistry();
        if ($registry === null) {
            return $code;
        }
        try {
            $provider = $registry->getProvider($code);
            if ($provider === null) {
                return $code;
            }
            $label = trim($provider->getLabel());

            return $label !== '' ? $label : $code;
        } catch (\Throwable) {
            return $code;
        }
    }

    /** @return array<string, mixed> */
    private function decodeSnapshot(string $json): array
    {
        $json = trim($json);
        if ($json === '') {
            return [];
        }
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function stores(): ?StoreCatalogInterface
    {
        if ($this->stores instanceof StoreCatalogInterface) {
            return $this->stores;
        }
        try {
            $resolved = ObjectManager::getInstance(StoreCatalogInterface::class);

            return $resolved instanceof StoreCatalogInterface ? $resolved : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function channels(): ?SalesChannelCatalogInterface
    {
        if ($this->channels instanceof SalesChannelCatalogInterface) {
            return $this->channels;
        }
        try {
            $resolved = ObjectManager::getInstance(SalesChannelCatalogInterface::class);

            return $resolved instanceof SalesChannelCatalogInterface ? $resolved : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function displayTypes(): ?ScopeDisplayTypeResolver
    {
        if ($this->displayTypes instanceof ScopeDisplayTypeResolver) {
            return $this->displayTypes;
        }
        try {
            $resolved = ObjectManager::getInstance(ScopeDisplayTypeResolver::class);

            return $resolved instanceof ScopeDisplayTypeResolver ? $resolved : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function displayTypeRegistry(): ?ScopeDisplayTypeRegistry
    {
        if ($this->displayTypeRegistry instanceof ScopeDisplayTypeRegistry) {
            return $this->displayTypeRegistry;
        }
        try {
            $resolved = ObjectManager::getInstance(ScopeDisplayTypeRegistry::class);

            return $resolved instanceof ScopeDisplayTypeRegistry ? $resolved : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
