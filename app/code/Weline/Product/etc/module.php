<?php

declare(strict_types=1);

return [
    'name' => 'Weline_Product',
    'version' => '1.1.33',
    'requires' => [
        'Weline_Acl' => '*',
        'Weline_Backend' => '*',
        'Weline_Catalog' => '*',
        'Weline_DataTable' => '*',
        'Weline_Framework' => '*',
        'Weline_I18n' => '*',
        'Weline_Tax' => '*',
        'Weline_Websites' => '*',
        'Weline_Eav' => '*',
        'Weline_FileManager' => '*',
    ],
    'optional' => [
        'Weline_Affiliate' => '*',
        'Weline_Ai' => '*',
        'Weline_Queue' => '>=1.2.5',
        'Weline_Api' => '*',
        'Weline_DeveloperWorkspace' => '*',
        'Weline_B2B' => '*',
        'Weline_Cart' => '*',
        'Weline_Customer' => '*',
        'Weline_Inventory' => '*',
        'Weline_MediaManager' => '*',
        'Weline_Order' => '*',
        'Weline_Review' => '*',
        'Weline_Seo' => '*',
        'Weline_Geo' => '*',
        'Weline_Promotion' => '*',
        'Weline_Marketing' => '*',
        'Weline_Captcha' => '*',
        'Weline_Mail' => '*',
        'Weline_Theme' => '*',
        'Weline_Shipping' => '*',
        'Weline_Currency' => '*',
        'Weline_Payment' => '*',
        'Weline_Search' => '*',
        'Weline_Smtp' => '*',
        'Weline_Cdn' => '*',
        'Weline_RecentlyViewed' => '*',
        'Weline_Server' => '*',
        'Weline_Filters' => '*',
        'Weline_EditorManager' => '*',
        'Weline_CKEditorEditorManager' => '*',
    ],
    'provides' => [
        \Weline\Framework\Http\Fpc\BinQueryCanonicalUriResolverInterface::class
            => \Weline\Product\Integration\Framework\BinQueryCanonicalUriResolver::class,
        'theme.public_route.product'
            => \Weline\Product\Controller\Router::class,
        'worker_preload_provider.Weline_Product_CategoryTree'
            => \Weline\Product\Service\Preload\CategoryTreeWarmupPreloadProvider::class,
        'view_warmup_contribution.Weline_Product'
            => \Weline\Product\Api\View\ViewWarmupContributionProvider::class,
        'storefront_hot_cache_bag_warmup.Weline_Product'
            => \Weline\Product\Api\Runtime\StorefrontHotCacheBagWarmupProvider::class,
        'cache.warmer.product.search_projection_scope'
            => \Weline\Product\Service\SearchProjectionScopeCacheWarmer::class,
        'cache.warmer.product.homepage_shelf_offers_locale'
            => \Weline\Product\Service\HomepageShelfOffersLocaleCacheWarmer::class,
        'cache.warmer.product.plp_light'
            => \Weline\Product\Service\PlpFilterOffersLightCacheWarmer::class,
        'process_cache_resetter.Weline_Product'
            => \Weline\Product\Api\Runtime\ProcessCacheResetter::class,
        'storefront.page_prefetch.Weline_Product'
            => \Weline\Product\Api\Runtime\StorefrontPagePrefetchContribution::class,
        \Weline\Product\Api\ProductAdminCommandInterface::class
            => \Weline\Product\Service\ProductAdminCommandService::class,
        \Weline\Product\Api\ProductAdminReadInterface::class
            => \Weline\Product\Service\ProductAdminReadService::class,
        \Weline\Product\Api\ProductIdentityV2ResolverInterface::class
            => \Weline\Product\Service\ProductIdentityV2Service::class,
        \Weline\Product\Api\ProductIdentityCutoverPolicyInterface::class
            => \Weline\Product\Service\ProductIdentityCutoverService::class,
        \Weline\Product\Api\ProductIdentityResolverInterface::class
            => \Weline\Product\Service\CompatibleProductIdentityResolver::class,
        \Weline\Product\Api\ProductSearchProjectionMutationCoordinatorInterface::class
            => \Weline\Product\Service\ProductSearchProjectionMutationCoordinator::class,
        \Weline\Product\Api\ProductDownloadEntitlementInterface::class
            => \Weline\Product\Service\ProductDownloadEntitlementService::class,
        \Weline\Product\Api\OrderDigitalDeliveryProjectionInterface::class
            => \Weline\Product\Service\OrderDigitalDeliveryProjectionService::class,
        \Weline\Product\Api\StorefrontOfferPriceAssemblerInterface::class
            => \Weline\Product\Service\Storefront\StorefrontOfferPriceAssembler::class,
        \Weline\Product\Api\CatalogFeedOfferReaderInterface::class
            => \Weline\Product\Service\CatalogFeed\CatalogFeedOfferReader::class,
        \Weline\Product\Api\CatalogFeedOfferCandidateSourceInterface::class
            => \Weline\Product\Service\CatalogFeed\OrmCatalogFeedOfferCandidateSource::class,
        \Weline\Product\Api\ProductQuoteRequestSubmitInterface::class
            => \Weline\Product\Service\ProductQuoteRequestService::class,
        \Weline\Cart\Api\CartPriceSellabilityProviderInterface::class
            => \Weline\Product\Integration\Cart\ProductCartPriceSellabilityProvider::class,
        \Weline\Cart\Api\ProductTypeRequiresShippingResolverInterface::class
            => \Weline\Product\Integration\Cart\ProductTypeRequiresShippingResolver::class,
        \Weline\Order\Api\OrderCatalogImageResolverInterface::class
            => \Weline\Product\Integration\Order\OrderCatalogImageResolver::class,
        'template_cache_policy.Weline_Product' => \Weline\Product\Api\View\TemplateCachePolicyProvider::class,
    ],
];
