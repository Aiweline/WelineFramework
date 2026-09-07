<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\CarrierRegion;
use Weline\Shipping\Model\Region;

/**
 * 覆盖/可售/禁运共用的「祖先命中」规则匹配。
 * 国家优先：同国下，规则为地址自身或其任一祖先（省/市/区）即命中。
 */
final class CoverageRuleMatcher
{
    public function __construct(
        private readonly ObjectManager $objectManager,
        private readonly RegionService $regionService,
    ) {
    }

    /**
     * @param array<string, mixed> $address
     * @param list<array<string, mixed>> $rules
     */
    public function addressMatchesAnyRule(array $address, array $rules): bool
    {
        if ($rules === []) {
            return false;
        }
        $resolved = $this->resolveAddressLevels($address);
        $country = $resolved['country_code'];
        if ($country === '') {
            return false;
        }

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            if (strtoupper(trim((string)($rule['country_code'] ?? ''))) !== $country) {
                continue;
            }
            $type = strtolower(trim((string)($rule['region_type'] ?? '')));
            if ($type === CarrierRegion::TYPE_COUNTRY || $type === 'country') {
                return true;
            }
            if ($this->ruleHitsResolved($rule, $resolved)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $address
     * @return array{
     *   country_code:string,
     *   chain:list<array{region_type:string,region_id:int,region_code:string,region_name:string}>,
     *   by_type:array<string, array{region_id:int,region_code:string,region_name:string}>
     * }
     */
    public function resolveAddressLevels(array $address): array
    {
        $country = strtoupper(trim((string)($address['country_code'] ?? $address['country'] ?? '')));
        $province = trim((string)($address['province'] ?? $address['region'] ?? ''));
        $city = trim((string)($address['city'] ?? ''));
        $district = trim((string)($address['district'] ?? ''));

        $byType = [];
        $explicit = [
            Region::TYPE_PROVINCE => [
                'region_id' => (int)($address['province_region_id'] ?? $address['province_id'] ?? 0),
                'region_code' => trim((string)($address['province_code'] ?? '')),
                'region_name' => $province,
            ],
            Region::TYPE_CITY => [
                'region_id' => (int)($address['city_region_id'] ?? $address['city_id'] ?? 0),
                'region_code' => trim((string)($address['city_code'] ?? '')),
                'region_name' => $city,
            ],
            Region::TYPE_DISTRICT => [
                'region_id' => (int)($address['district_region_id'] ?? $address['district_id'] ?? 0),
                'region_code' => trim((string)($address['district_code'] ?? '')),
                'region_name' => $district,
            ],
        ];
        foreach ($explicit as $type => $row) {
            if ($row['region_id'] > 0 || $row['region_code'] !== '' || $row['region_name'] !== '') {
                $byType[$type] = $row;
            }
        }

        $leaf = $this->regionService->findByLocation(
            $country !== '' ? $country : 'CN',
            $province !== '' ? $province : null,
            $city !== '' ? $city : null,
            $district !== '' ? $district : null,
        );
        $chain = [];
        if ($leaf instanceof Region && $leaf->getId()) {
            $cursor = $leaf;
            $guard = 0;
            while ($cursor instanceof Region && $cursor->getId() && $guard < 8) {
                $type = (string)$cursor->getData(Region::schema_fields_REGION_TYPE);
                $entry = [
                    'region_type' => $type,
                    'region_id' => (int)$cursor->getId(),
                    'region_code' => (string)$cursor->getData(Region::schema_fields_REGION_CODE),
                    'region_name' => (string)$cursor->getData(Region::schema_fields_REGION_NAME),
                ];
                $chain[] = $entry;
                if ($type !== Region::TYPE_COUNTRY) {
                    $byType[$type] = [
                        'region_id' => $entry['region_id'],
                        'region_code' => $entry['region_code'],
                        'region_name' => $entry['region_name'],
                    ];
                }
                if ($country === '') {
                    $country = strtoupper((string)$cursor->getData(Region::schema_fields_COUNTRY_CODE));
                }
                $parentId = (int)$cursor->getData(Region::schema_fields_PARENT_REGION_ID);
                if ($parentId <= 0) {
                    break;
                }
                /** @var Region $parent */
                $parent = $this->objectManager->getInstance(Region::class, [], false);
                $parent->load($parentId);
                $cursor = $parent->getId() ? $parent : null;
                $guard++;
            }
        }

        // Always include country synthetic level
        $byType[Region::TYPE_COUNTRY] = [
            'region_id' => 0,
            'region_code' => $country,
            'region_name' => $country,
        ];

        return [
            'country_code' => $country,
            'chain' => $chain,
            'by_type' => $byType,
        ];
    }

    /**
     * @param array<string, mixed> $rule
     * @param array{country_code:string,chain:list,by_type:array} $resolved
     */
    private function ruleHitsResolved(array $rule, array $resolved): bool
    {
        $type = strtolower(trim((string)($rule['region_type'] ?? '')));
        $ruleId = (int)($rule['region_id'] ?? 0);
        $ruleCode = strtoupper(trim((string)($rule['region_code'] ?? '')));
        $ruleStreet = (int)($rule['street_id'] ?? 0);

        if ($type === CarrierRegion::TYPE_STREET || $type === 'street') {
            // street: exact street_id or code only (no ancestor expand)
            $candStreet = (int)($resolved['by_type']['street']['region_id'] ?? 0);
            // address may carry street_id separately
            return false;
        }

        // Ancestor hit: rule region appears in address chain OR matches by_type of same level
        foreach ($resolved['chain'] as $node) {
            if (!is_array($node)) {
                continue;
            }
            if ($ruleId > 0 && $ruleId === (int)($node['region_id'] ?? 0)) {
                return true;
            }
            $nodeCode = strtoupper(trim((string)($node['region_code'] ?? '')));
            $nodeName = trim((string)($node['region_name'] ?? ''));
            if ($ruleCode !== '' && ($ruleCode === $nodeCode || strcasecmp($ruleCode, $nodeName) === 0)) {
                return true;
            }
        }

        $cand = $resolved['by_type'][$type] ?? null;
        if (!is_array($cand)) {
            // Also try matching rule against any by_type entry with same id (ancestor stored under different type key)
            foreach ($resolved['by_type'] as $candRow) {
                if (!is_array($candRow)) {
                    continue;
                }
                if ($ruleId > 0 && $ruleId === (int)($candRow['region_id'] ?? 0)) {
                    return true;
                }
            }

            return false;
        }
        if ($ruleId > 0 && $ruleId === (int)($cand['region_id'] ?? 0)) {
            return true;
        }
        $candCode = strtoupper(trim((string)($cand['region_code'] ?? '')));
        $candName = trim((string)($cand['region_name'] ?? ''));
        if ($ruleCode !== '' && ($ruleCode === $candCode || strcasecmp($ruleCode, $candName) === 0)) {
            return true;
        }

        return false;
    }
}
