<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Product\Model\Shard\Product;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Repository\ProductRepository;

/**
 * Seeds zh_Hans_CN product name overlays from the current English catalog titles.
 */
final class ProductCatalogNameZhSeeder
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly AttributeValueRepository $attributes,
        private readonly ProductCatalogNameTranslator $translator = new ProductCatalogNameTranslator(),
    ) {
    }

    /**
     * @return array{written:int, skipped:int, samples:list<array{product_id:int, en:string, zh:string}>}
     */
    public function seed(int $websiteId = 0, int $storeId = 0): array
    {
        $written = 0;
        $skipped = 0;
        $samples = [];

        foreach ($this->products->listAll($websiteId) as $product) {
            $productId = (int)($product[Product::schema_fields_ID] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $english = $this->attributes->read(
                $websiteId,
                $storeId,
                'product',
                $productId,
                'name',
                '',
                [''],
            );
            if (!$english->isExplicit()) {
                ++$skipped;
                continue;
            }

            $englishName = trim((string)$english->value);
            $zhName = $this->translator->toZhHans($englishName);
            if ($zhName === '' || $zhName === $englishName) {
                ++$skipped;
                continue;
            }

            $this->attributes->writeExplicit(
                $websiteId,
                $storeId,
                'product',
                $productId,
                'name',
                'zh_Hans_CN',
                $zhName,
            );
            ++$written;
            if (count($samples) < 8) {
                $samples[] = [
                    'product_id' => $productId,
                    'en' => $englishName,
                    'zh' => $zhName,
                ];
            }
        }

        return [
            'written' => $written,
            'skipped' => $skipped,
            'samples' => $samples,
        ];
    }
}
