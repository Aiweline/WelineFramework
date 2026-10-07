<?php

declare(strict_types=1);

namespace Weline\Tax\Service\RateSync;

use Weline\Tax\Api\TaxRateRemoteProviderInterface;
use Weline\Tax\Service\TaxDefaultSeedService;

/**
 * Built-in free provider: same matrix as TaxDefaultSeedService::RULES.
 */
final class StaticSeedTaxRateProvider implements TaxRateRemoteProviderInterface
{
    public const CODE = 'static';

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return (string)__('静态种子税率（多国 VAT/GST 基线）');
    }

    public function tier(): string
    {
        return self::TIER_FREE;
    }

    public function fetchCandidates(int $websiteId): array
    {
        $out = [];
        foreach (TaxDefaultSeedService::rules() as $rule) {
            $out[] = [
                'jurisdiction_key' => strtoupper((string)$rule['jurisdiction_key']),
                'class_code' => strtolower((string)$rule['class_code']),
                'rate_bps' => (int)$rule['rate_bps'],
                'source' => self::CODE,
                'source_meta' => [
                    'website_id' => max(0, $websiteId),
                    'seed_revision' => TaxDefaultSeedService::SEED_REVISION,
                ],
            ];
        }

        return $out;
    }
}
