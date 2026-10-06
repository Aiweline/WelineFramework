<?php

declare(strict_types=1);

namespace Weline\Tax\Api;

/**
 * Remote / static tax-rate candidate source for offline ratesync (never on checkout hot path).
 *
 * Register via Tax `extends.php` → `TaxRateRemoteProvider` or built-in registry load.
 */
interface TaxRateRemoteProviderInterface
{
    public const TIER_FREE = 'free';
    public const TIER_PROFESSIONAL = 'professional';

    public function code(): string;

    public function label(): string;

    /** @return self::TIER_* */
    public function tier(): string;

    /**
     * @return list<array{
     *   jurisdiction_key:string,
     *   class_code:string,
     *   rate_bps:int,
     *   source:string,
     *   source_meta?:array<string,mixed>
     * }>
     */
    public function fetchCandidates(int $websiteId): array;
}
