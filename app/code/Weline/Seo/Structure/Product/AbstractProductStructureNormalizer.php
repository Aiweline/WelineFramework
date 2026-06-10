<?php

declare(strict_types=1);

namespace Weline\Seo\Structure\Product;

use Weline\Seo\Structure\AbstractSeoStructureNormalizer;
use Weline\Seo\Structure\SeoStructureFactsInterface;

/**
 * 商品结构化事实归一化基类。
 *
 * 标准 context 键：product
 * 典型字段：name、sku、price、image、offers、brand、availability
 */
abstract class AbstractProductStructureNormalizer extends AbstractSeoStructureNormalizer implements SeoStructureFactsInterface
{
    /**
     * @return array<string, mixed>
     */
    abstract public function normalize(mixed $product): array;

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    protected function baseProductFacts(array $product): array
    {
        $facts = [];
        foreach ([
            'name' => ['name', 'title'],
            'sku' => ['sku'],
            'description' => ['description', 'short_description'],
            'image' => ['image', 'main_image'],
            'brand' => ['brand'],
            'price' => ['price', 'final_price'],
            'currency' => ['currency', 'price_currency'],
            'availability' => ['availability', 'stock_status'],
            'url' => ['url', 'canonical'],
        ] as $target => $aliases) {
            $value = $this->firstString($product, $aliases);
            if ($value !== '') {
                $facts[$target] = $value;
            }
        }

        return $facts;
    }
}
