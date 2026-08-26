<?php

declare(strict_types=1);

namespace Weline\Review\Api;

/**
 * SEO-facing review aggregates for the active request locale.
 */
interface ReviewSeoFactsInterface
{
    /**
     * @return array{
     *   success:bool,
     *   review_count:int,
     *   average_rating:float,
     *   reviews:list<array<string,mixed>>
     * }
     */
    public function seoFacts(string $typeCode, string $externalEntityUuid, int $sampleSize = 10): array;
}
