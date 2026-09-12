<?php

declare(strict_types=1);

namespace Weline\Dropship\Service;

/**
 * Pure selection policy: Dropship Fake/demo SKUs with zero product images.
 */
final class DemoSkuNoImagePolicy
{
    public function isDemoSku(string $sku): bool
    {
        $sku = strtoupper(trim($sku));
        if ($sku === '') {
            return false;
        }

        return str_starts_with($sku, 'DS-DEMO') || str_starts_with($sku, 'DS-FAKE');
    }

    public function shouldRemove(string $sku, int $imageCount): bool
    {
        return $this->isDemoSku($sku) && $imageCount <= 0;
    }
}
