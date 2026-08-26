<?php

declare(strict_types=1);

namespace Weline\Product\Api;

use Weline\Product\Api\Data\ProductTypeDefinition;
use Weline\Product\Api\Data\ProductValidationContext;
use Weline\Product\Api\Data\ProductValidationResult;

/**
 * Provider V2 adds immutable type/schema and publish diagnostics while retaining V1 compatibility.
 */
interface ProductProviderV2Interface extends ProductProviderInterface
{
    public function getDefinition(): ProductTypeDefinition;

    public function validateForPublish(ProductValidationContext $context): ProductValidationResult;
}
