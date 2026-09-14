<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\ShippingProfile;
use Weline\Shipping\Model\ShippingProfileService;
use Weline\Shipping\Model\ShippingService;

/**
 * Resolve shipping_profile_code on lines to General/Heavy/custom and list service ids.
 */
final class ShippingProfileResolver
{
    public const SOURCE_OFFER = 'offer';
    public const SOURCE_CATEGORY = 'category';
    public const SOURCE_GENERAL = 'general';

    public function __construct(private readonly ObjectManager $objectManager)
    {
    }

    /**
     * @param array{scope_type?:string,scope_id?:int,website_id?:int}|null $context
     * @return array{profile:ShippingProfile,source:string}|null
     */
    public function resolveByCode(string $code, ?array $context = null): ?array
    {
        $code = trim($code);
        if ($code !== '') {
            foreach ($this->profileScopeCandidates($context) as [$scopeType, $scopeId]) {
                /** @var ShippingProfile $model */
                $model = $this->objectManager->getInstance(ShippingProfile::class, [], false);
                $items = $model->reset()
                    ->where(ShippingProfile::schema_fields_SCOPE_TYPE, $scopeType)
                    ->where(ShippingProfile::schema_fields_SCOPE_ID, $scopeId)
                    ->where(ShippingProfile::schema_fields_PROFILE_CODE, $code)
                    ->where(ShippingProfile::schema_fields_IS_ACTIVE, 1)
                    ->select()
                    ->fetch()
                    ->getItems();
                $row = is_array($items) ? ($items[0] ?? null) : null;
                if ($row instanceof ShippingProfile && (int)$row->getId() > 0) {
                    return ['profile' => $row, 'source' => self::SOURCE_OFFER];
                }
            }
        }

        foreach ($this->profileScopeCandidates($context) as [$scopeType, $scopeId]) {
            $general = $this->resolveGeneral($scopeType, $scopeId);
            if ($general !== null) {
                return $general;
            }
        }

        return null;
    }

    /**
     * Profile lookup order: request scope → website:0 seed (same as service-layer fallback).
     *
     * @param array{scope_type?:string,scope_id?:int,website_id?:int}|null $context
     * @return list<array{0:string,1:int}>
     */
    private function profileScopeCandidates(?array $context): array
    {
        $scopeType = (string)($context['scope_type'] ?? ShippingProfile::SCOPE_WEBSITE);
        $scopeId = (int)($context['scope_id'] ?? $context['website_id'] ?? 0);
        $candidates = [[$scopeType, $scopeId]];
        if ($scopeType !== ShippingProfile::SCOPE_WEBSITE || $scopeId !== 0) {
            $candidates[] = [ShippingProfile::SCOPE_WEBSITE, 0];
        }

        return $candidates;
    }

    /**
     * @return array{profile:ShippingProfile,source:string}|null
     */
    public function resolveGeneral(string $scopeType, int $scopeId): ?array
    {
        /** @var ShippingProfile $model */
        $model = $this->objectManager->getInstance(ShippingProfile::class, [], false);
        $items = $model->reset()
            ->where(ShippingProfile::schema_fields_SCOPE_TYPE, $scopeType)
            ->where(ShippingProfile::schema_fields_SCOPE_ID, $scopeId)
            ->where(ShippingProfile::schema_fields_IS_GENERAL, 1)
            ->where(ShippingProfile::schema_fields_IS_ACTIVE, 1)
            ->select()
            ->fetch()
            ->getItems();
        $row = is_array($items) ? ($items[0] ?? null) : null;
        if ($row instanceof ShippingProfile && (int)$row->getId() > 0) {
            return ['profile' => $row, 'source' => self::SOURCE_GENERAL];
        }
        $items = $model->reset()
            ->where(ShippingProfile::schema_fields_SCOPE_TYPE, $scopeType)
            ->where(ShippingProfile::schema_fields_SCOPE_ID, $scopeId)
            ->where(ShippingProfile::schema_fields_PROFILE_CODE, ShippingProfile::SEED_GENERAL)
            ->where(ShippingProfile::schema_fields_IS_ACTIVE, 1)
            ->select()
            ->fetch()
            ->getItems();
        $row = is_array($items) ? ($items[0] ?? null) : null;
        if ($row instanceof ShippingProfile && (int)$row->getId() > 0) {
            return ['profile' => $row, 'source' => self::SOURCE_GENERAL];
        }

        return null;
    }

    /**
     * @return list<int>
     */
    public function serviceIdsForProfile(int $profileId): array
    {
        if ($profileId <= 0) {
            return [];
        }
        /** @var ShippingProfileService $link */
        $link = $this->objectManager->getInstance(ShippingProfileService::class, [], false);
        $items = $link->reset()
            ->where(ShippingProfileService::schema_fields_PROFILE_ID, $profileId)
            ->select()
            ->fetch()
            ->getItems();
        $ids = [];
        foreach (is_array($items) ? $items : [] as $row) {
            if (!$row instanceof ShippingProfileService) {
                continue;
            }
            $sid = (int)$row->getData(ShippingProfileService::schema_fields_SERVICE_ID);
            if ($sid > 0) {
                $ids[] = $sid;
            }
        }

        return $ids;
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @param array{scope_type?:string,scope_id?:int,website_id?:int}|null $context
     * @return array<string, array{profile_code:string,source:string,service_ids:list<int>,lines:list<array<string,mixed>>}>
     */
    public function groupLinesByProfile(array $lines, ?array $context = null): array
    {
        $groups = [];
        foreach ($lines as $line) {
            if (!(bool)($line['requires_shipping'] ?? true)) {
                continue;
            }
            $code = trim((string)($line['shipping_profile_code']
                ?? ($line['fulfillment_metadata']['shipping_profile_code'] ?? '')));
            $resolved = $this->resolveByCode($code, $context);
            if ($resolved === null) {
                $profileCode = ShippingProfile::SEED_GENERAL;
                $source = self::SOURCE_GENERAL;
                $serviceIds = [];
            } else {
                $profile = $resolved['profile'];
                $profileCode = (string)$profile->getData(ShippingProfile::schema_fields_PROFILE_CODE);
                $source = $resolved['source'];
                $serviceIds = $this->serviceIdsForProfile((int)$profile->getId());
            }
            if (!isset($groups[$profileCode])) {
                $groups[$profileCode] = [
                    'profile_code' => $profileCode,
                    'source' => $source,
                    'service_ids' => $serviceIds,
                    'lines' => [],
                ];
            }
            $groups[$profileCode]['lines'][] = $line;
        }

        return $groups;
    }
}
