<?php

declare(strict_types=1);

namespace Weline\Product\Api;

/** 可选批量身份读取；键为输入 UUID，缺失项不返回。 */
interface ProductIdentityBatchResolverInterface extends ProductIdentityResolverInterface
{
    /** @param list<string> $uuids @return array<string, ProductIdentity> */
    public function resolveByOfferUuids(array $uuids): array;
    /** @param list<string> $uuids @return array<string, ProductIdentity> */
    public function resolveByProductUuids(array $uuids): array;
}
