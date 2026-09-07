<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Shipping\Model\DestinationRegion;

/**
 * 可售目的地运行时：website ∪ store ∪ channel。
 * 空列表 = 未配置白名单（软过渡，不拦截）。
 */
final class DestinationService
{
    public function __construct(
        private readonly ObjectManager $objectManager,
    ) {
    }

    /**
     * @return array{website_id:int,store_id:int,channel_id:int}
     */
    public function resolveContext(?array $override = null): array
    {
        $websiteId = isset($override['website_id'])
            ? (int)$override['website_id']
            : (int)RequestContext::getWelineWebsiteId();
        $storeId = isset($override['store_id'])
            ? (int)$override['store_id']
            : (int)RequestContext::getWelineStoreId();
        $channelId = isset($override['channel_id'])
            ? (int)$override['channel_id']
            : (int)RequestContext::getWelineChannelId();

        return [
            'website_id' => max(0, $websiteId),
            'store_id' => max(0, $storeId),
            'channel_id' => max(0, $channelId),
        ];
    }

    /**
     * @param array{website_id?:int,store_id?:int,channel_id?:int}|null $context
     * @return list<array<string, mixed>>
     */
    public function loadActiveRules(?array $context = null): array
    {
        $ctx = $this->resolveContext($context);
        try {
            /** @var DestinationRegion $model */
            $model = $this->objectManager->getInstance(DestinationRegion::class);
        } catch (\Throwable) {
            return [];
        }
        $scopes = [
            [DestinationRegion::SCOPE_WEBSITE, $ctx['website_id']],
            [DestinationRegion::SCOPE_STORE, $ctx['store_id']],
            [DestinationRegion::SCOPE_CHANNEL, $ctx['channel_id']],
        ];
        $out = [];
        foreach ($scopes as [$type, $id]) {
            try {
                $items = $model->reset()
                    ->where(DestinationRegion::schema_fields_SCOPE_TYPE, $type)
                    ->where(DestinationRegion::schema_fields_SCOPE_ID, (int)$id)
                    ->where(DestinationRegion::schema_fields_IS_ACTIVE, 1)
                    ->select()
                    ->fetch()
                    ->getItems();
            } catch (\Throwable) {
                return [];
            }
            foreach ($items as $item) {
                if (!$item instanceof DestinationRegion) {
                    continue;
                }
                $out[] = [
                    'destination_id' => (int)$item->getId(),
                    'scope_type' => (string)$item->getData(DestinationRegion::schema_fields_SCOPE_TYPE),
                    'scope_id' => (int)$item->getData(DestinationRegion::schema_fields_SCOPE_ID),
                    'region_type' => (string)$item->getData(DestinationRegion::schema_fields_REGION_TYPE),
                    'country_code' => (string)$item->getData(DestinationRegion::schema_fields_COUNTRY_CODE),
                    'region_id' => (int)$item->getData(DestinationRegion::schema_fields_REGION_ID),
                    'region_code' => (string)$item->getData(DestinationRegion::schema_fields_REGION_CODE),
                    'street_id' => (int)$item->getData(DestinationRegion::schema_fields_STREET_ID),
                ];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $address
     * @param array{website_id?:int,store_id?:int,channel_id?:int}|null $context
     */
    public function addressAllowed(array $address, ?array $context = null): bool
    {
        $rules = $this->loadActiveRules($context);
        if ($rules === []) {
            return true;
        }

        return $this->rulesCoverAddress($rules, $address);
    }

    /**
     * @param list<array<string, mixed>> $rules
     * @param array<string, mixed> $address
     */
    public function rulesCoverAddress(array $rules, array $address): bool
    {
        $matcher = $this->objectManager->getInstance(CoverageRuleMatcher::class);

        return $matcher->addressMatchesAnyRule($address, $rules);
    }
}
