<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\View\Template;
use Weline\Product\Helper\StorefrontOfferResolver;

final class StorefrontOfferContextTest extends TestCase
{
    private ?Context $previousContext;

    protected function setUp(): void
    {
        $this->previousContext = Context::getCurrent();
        Context::enter(new Context());
    }

    protected function tearDown(): void
    {
        Context::leave();
        if ($this->previousContext !== null) {
            Context::enter($this->previousContext);
        }
    }

    public function testResolvedOfferReachesIndependentSlotTemplateWithoutCatalogLookup(): void
    {
        $offer = ['product_id' => 83, 'global_offer_uuid' => 'selected-variant',
            'name' => 'Selected offer', 'unit_price_minor' => 12345, 'currency' => 'USD'];
        try {
            StorefrontOfferResolver::rememberResolvedOffer($offer);
            $template = (new \ReflectionClass(Template::class))->newInstanceWithoutConstructor();
            self::assertSame($offer, StorefrontOfferResolver::resolve($template));
        } catch (\Throwable $error) {
            self::fail('An already resolved offer must render without another dependency: ' . $error->getMessage());
        }
    }

    public function testUnselectedVariantRemainsUnselectedAndDoesNotLeakToNextRequest(): void
    {
        $offer = ['product_id' => 83, 'global_offer_uuid' => '', 'selection_required' => true,
            'sellable' => false, 'name' => 'Choose size'];
        try {
            StorefrontOfferResolver::rememberResolvedOffer($offer);
            $template = (new \ReflectionClass(Template::class))->newInstanceWithoutConstructor();
            self::assertSame($offer, StorefrontOfferResolver::resolve($template));
            Context::enter(new Context());
            self::assertSame([], StorefrontOfferResolver::currentOffer());
        } catch (\Throwable $error) {
            self::fail('Offer reuse must follow the request lifetime: ' . $error->getMessage());
        }
    }

    public function testAnExplicitDifferentCardDoesNotConsumeTheCurrentPdpOffer(): void
    {
        StorefrontOfferResolver::rememberResolvedOffer([
            'product_id' => 83, 'global_offer_uuid' => 'pdp-offer', 'name' => 'PDP',
        ]);
        $template = (new \ReflectionClass(Template::class))->newInstanceWithoutConstructor();
        $template->setData('card_product_id', 84);
        // A cold dependency deliberately has no backend services. Its failure is
        // allowed to produce [], but must never substitute the unrelated PDP.
        $catalog = (new \ReflectionClass(\Weline\Product\Service\StorefrontCatalogViewService::class))->newInstanceWithoutConstructor();
        \Weline\Framework\Manager\ObjectManager::setInstance(\Weline\Product\Service\StorefrontCatalogViewService::class, $catalog);
        try {
            self::assertSame([], StorefrontOfferResolver::resolve($template));
        } finally {
            \Weline\Framework\Manager\ObjectManager::clearInstances();
        }
    }
}
