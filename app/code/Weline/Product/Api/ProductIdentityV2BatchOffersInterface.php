<?php

declare(strict_types=1);

namespace Weline\Product\Api;

use Weline\Product\Api\Data\OfferIdentityV2;

/** 批量读取商品的有效 Offer 候选。 */
interface ProductIdentityV2BatchOffersInterface
{
    /** @param list<string> $uuids @return array<string, list<OfferIdentityV2>> */
    public function listOffersByProductUuids(array $uuids): array;
}
