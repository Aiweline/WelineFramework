<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Shipping\Model\EmbargoRegion;

/**
 * 运行时禁运评估：active system ∪ website ∪ store ∪ channel（system 最高优先）。
 */
final class EmbargoService
{
    public function __construct(
        private readonly ObjectManager $objectManager
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
     * @param array<string, mixed> $address country_code + optional region ids/codes + street_id
     * @param array{website_id?:int,store_id?:int,channel_id?:int}|null $context
     * @return array{blocked:bool,level:?string,message:string,matched:?array<string,mixed>,scope_type:?string}
     */
    public function evaluateAddress(array $address, ?array $context = null): array
    {
        $ctx = $this->resolveContext($context);
        $rules = $this->loadActiveRules($ctx);
        return $this->evaluateAgainstRules($address, $rules);
    }

    /**
     * @param array<string, mixed> $address
     * @param list<array<string, mixed>> $rules
     * @return array{blocked:bool,level:?string,message:string,matched:?array<string,mixed>,scope_type:?string}
     */
    public function evaluateAgainstRules(array $address, array $rules): array
    {
        if ($rules === []) {
            return $this->ok();
        }

        $country = strtoupper(trim((string)($address['country_code'] ?? $address['country'] ?? '')));
        $levels = [
            EmbargoRegion::TYPE_COUNTRY => [
                'region_id' => 0,
                'region_code' => $country,
                'street_id' => 0,
            ],
            EmbargoRegion::TYPE_PROVINCE => [
                'region_id' => (int)($address['province_region_id'] ?? $address['province_id'] ?? 0),
                'region_code' => trim((string)($address['province_code'] ?? $address['province'] ?? '')),
                'street_id' => 0,
            ],
            EmbargoRegion::TYPE_CITY => [
                'region_id' => (int)($address['city_region_id'] ?? $address['city_id'] ?? 0),
                'region_code' => trim((string)($address['city_code'] ?? $address['city'] ?? '')),
                'street_id' => 0,
            ],
            EmbargoRegion::TYPE_DISTRICT => [
                'region_id' => (int)($address['district_region_id'] ?? $address['district_id'] ?? 0),
                'region_code' => trim((string)($address['district_code'] ?? $address['district'] ?? '')),
                'street_id' => 0,
            ],
            EmbargoRegion::TYPE_STREET => [
                'region_id' => 0,
                'region_code' => trim((string)($address['street_code'] ?? $address['street'] ?? '')),
                'street_id' => (int)($address['street_id'] ?? 0),
            ],
        ];

        foreach ([
            EmbargoRegion::TYPE_COUNTRY,
            EmbargoRegion::TYPE_PROVINCE,
            EmbargoRegion::TYPE_CITY,
            EmbargoRegion::TYPE_DISTRICT,
            EmbargoRegion::TYPE_STREET,
        ] as $level) {
            foreach ($rules as $rule) {
                if (($rule['region_type'] ?? '') !== $level) {
                    continue;
                }
                if ($country === '' || strtoupper((string)$rule['country_code']) !== $country) {
                    continue;
                }
                if ($this->ruleMatches($rule, $levels[$level], $level)) {
                    return [
                        'blocked' => true,
                        'level' => $level,
                        'message' => $this->messageFor($level, (string)($rule['scope_type'] ?? '')),
                        'matched' => $rule,
                        'scope_type' => (string)($rule['scope_type'] ?? ''),
                    ];
                }
            }
        }

        return $this->ok();
    }

    /**
     * @param array<string, mixed> $address
     * @param array{website_id?:int,store_id?:int,channel_id?:int}|null $context
     * @throws \RuntimeException
     */
    public function assertAllowed(array $address, ?array $context = null): void
    {
        $result = $this->evaluateAddress($address, $context);
        if (!empty($result['blocked'])) {
            throw new \RuntimeException((string)$result['message']);
        }
    }

    /**
     * 国家级禁运代码集合（供邮编多国待选等）。
     *
     * @param array{website_id?:int,store_id?:int,channel_id?:int}|null $context
     * @return array<string, true>
     */
    public function embargoedCountryCodes(?array $context = null): array
    {
        $ctx = $this->resolveContext($context);
        $map = [];
        foreach ($this->loadActiveRules($ctx) as $rule) {
            if (($rule['region_type'] ?? '') !== EmbargoRegion::TYPE_COUNTRY) {
                continue;
            }
            $cc = strtoupper(trim((string)($rule['country_code'] ?? '')));
            if ($cc !== '' && preg_match('/^[A-Z]{2}$/', $cc)) {
                $map[$cc] = true;
            }
        }

        return $map;
    }

    /**
     * 活跃的省/市/区/街禁运规则（供地址选择器下级打标；不含国家级）。
     *
     * @param array{website_id?:int,store_id?:int,channel_id?:int}|null $context
     * @return list<array{country_code:string,region_type:string,region_id:int,region_code:string,street_id:int,scope_type:string,reason_code:string}>
     */
    public function activeSubnationalRules(?array $context = null): array
    {
        $ctx = $this->resolveContext($context);
        $out = [];
        foreach ($this->loadActiveRules($ctx) as $rule) {
            $type = (string)($rule['region_type'] ?? '');
            if ($type === '' || $type === EmbargoRegion::TYPE_COUNTRY) {
                continue;
            }
            $cc = strtoupper(trim((string)($rule['country_code'] ?? '')));
            if ($cc === '' || !preg_match('/^[A-Z]{2}$/', $cc)) {
                continue;
            }
            $out[] = [
                'country_code' => $cc,
                'region_type' => $type,
                'region_id' => (int)($rule['region_id'] ?? 0),
                'region_code' => (string)($rule['region_code'] ?? ''),
                'street_id' => (int)($rule['street_id'] ?? 0),
                'scope_type' => (string)($rule['scope_type'] ?? ''),
                'reason_code' => (string)($rule['reason_code'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return array{blocked:bool,level:null,message:string,matched:null,scope_type:null}
     */
    private function ok(): array
    {
        return [
            'blocked' => false,
            'level' => null,
            'message' => '',
            'matched' => null,
            'scope_type' => null,
        ];
    }

    /**
     * @param array{website_id:int,store_id:int,channel_id:int} $ctx
     * @return list<array<string, mixed>>
     */
    private function loadActiveRules(array $ctx): array
    {
        try {
            /** @var EmbargoRegion $model */
            $model = $this->objectManager->getInstance(EmbargoRegion::class);
        } catch (\Throwable) {
            return [];
        }
        $scopes = [
            [EmbargoRegion::SCOPE_SYSTEM, 0],
            [EmbargoRegion::SCOPE_WEBSITE, $ctx['website_id']],
            [EmbargoRegion::SCOPE_STORE, $ctx['store_id']],
            [EmbargoRegion::SCOPE_CHANNEL, $ctx['channel_id']],
        ];
        $out = [];
        foreach ($scopes as [$type, $id]) {
            try {
                $items = $model->reset()
                    ->where(EmbargoRegion::schema_fields_SCOPE_TYPE, $type)
                    ->where(EmbargoRegion::schema_fields_SCOPE_ID, (int)$id)
                    ->where(EmbargoRegion::schema_fields_IS_ACTIVE, 1)
                    ->select()
                    ->fetch()
                    ->getItems();
            } catch (\Throwable) {
                return [];
            }
            foreach ($items as $item) {
                if (!$item instanceof EmbargoRegion) {
                    continue;
                }
                $out[] = [
                    'embargo_id' => (int)$item->getId(),
                    'scope_type' => (string)$item->getData(EmbargoRegion::schema_fields_SCOPE_TYPE),
                    'scope_id' => (int)$item->getData(EmbargoRegion::schema_fields_SCOPE_ID),
                    'region_type' => (string)$item->getData(EmbargoRegion::schema_fields_REGION_TYPE),
                    'country_code' => (string)$item->getData(EmbargoRegion::schema_fields_COUNTRY_CODE),
                    'region_id' => (int)$item->getData(EmbargoRegion::schema_fields_REGION_ID),
                    'region_code' => (string)$item->getData(EmbargoRegion::schema_fields_REGION_CODE),
                    'street_id' => (int)$item->getData(EmbargoRegion::schema_fields_STREET_ID),
                    'reason_code' => (string)$item->getData(EmbargoRegion::schema_fields_REASON_CODE),
                ];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $rule
     * @param array{region_id:int,region_code:string,street_id:int} $candidate
     */
    private function ruleMatches(array $rule, array $candidate, string $level): bool
    {
        if ($level === EmbargoRegion::TYPE_COUNTRY) {
            return true;
        }
        if ($level === EmbargoRegion::TYPE_STREET) {
            $ruleStreet = (int)($rule['street_id'] ?? 0);
            if ($ruleStreet > 0 && $ruleStreet === (int)$candidate['street_id']) {
                return true;
            }
            $code = strtoupper(trim((string)($rule['region_code'] ?? '')));
            $cand = strtoupper(trim((string)$candidate['region_code']));

            return $code !== '' && $cand !== '' && $code === $cand;
        }
        $ruleId = (int)($rule['region_id'] ?? 0);
        if ($ruleId > 0 && $ruleId === (int)$candidate['region_id']) {
            return true;
        }
        $code = strtoupper(trim((string)($rule['region_code'] ?? '')));
        $cand = strtoupper(trim((string)$candidate['region_code']));

        return $code !== '' && $cand !== '' && $code === $cand;
    }

    private function messageFor(string $level, string $scopeType): string
    {
        $scopeLabel = match ($scopeType) {
            EmbargoRegion::SCOPE_SYSTEM => (string)__('系统'),
            EmbargoRegion::SCOPE_STORE => (string)__('店铺'),
            EmbargoRegion::SCOPE_CHANNEL => (string)__('渠道'),
            default => (string)__('网站'),
        };
        $levelLabel = match ($level) {
            EmbargoRegion::TYPE_PROVINCE => (string)__('省份'),
            EmbargoRegion::TYPE_CITY => (string)__('城市'),
            EmbargoRegion::TYPE_DISTRICT => (string)__('区县'),
            EmbargoRegion::TYPE_STREET => (string)__('街道'),
            default => (string)__('国家'),
        };

        return (string)__('%{1}不支持该%{2}', [$scopeLabel, $levelLabel]);
    }
}
