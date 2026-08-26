<?php

declare(strict_types=1);

namespace Weline\Product\Service\Provider;

use Weline\Product\Api\ProductProviderInterface;

final class BuiltInProductProviderCatalog
{
    /** @return list<ProductProviderInterface> */
    public static function additionalProviders(): array
    {
        return [
            new ConfigurableProductProvider(),
            new VirtualProductProvider(),
            new DownloadableProductProvider(),
            new BundleProductProvider(),
        ];
    }
}
