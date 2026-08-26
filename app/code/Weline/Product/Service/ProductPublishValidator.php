<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Product\Api\Data\ProductValidationContext;
use Weline\Product\Api\Data\ProductValidationResult;
use Weline\Product\Api\ProductProviderV2Interface;

final readonly class ProductPublishValidator
{
    public function __construct(private ProductProviderRegistry $providers)
    {
    }

    public function validate(ProductValidationContext $context): ProductValidationResult
    {
        $provider = $this->providers->getByType($context->productType, true);
        if ($provider === null) {
            return new ProductValidationResult(errors: [[
                'code' => 'product_provider_unavailable',
                'message' => (string)__('商品类型 Provider 不可用：%{1}', [$context->productType]),
                'path' => 'product_type',
            ]]);
        }
        if ($provider instanceof ProductProviderV2Interface) {
            return $provider->validateForPublish($context);
        }

        return (new ProductProviderV1Adapter($provider))->validateForPublish($context);
    }
}
