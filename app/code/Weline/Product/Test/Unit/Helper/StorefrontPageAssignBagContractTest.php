<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\View\Template;
use Weline\Product\Helper\StorefrontOfferResolver;
use Weline\Product\Helper\StorefrontPageAssignBag;

final class StorefrontPageAssignBagContractTest extends TestCase
{
    private ?Context $previousContext;

    protected function setUp(): void
    {
        $this->previousContext = Context::getCurrent();
        Context::enter(new Context());
        RequestContext::setId('product-page-assign-bag-test');
        StorefrontPageAssignBag::reset();
    }

    protected function tearDown(): void
    {
        StorefrontPageAssignBag::reset();
        Context::leave();
        if ($this->previousContext !== null) {
            Context::enter($this->previousContext);
        }
    }

    public function testControllerPublishSurvivesTemplateUnsetData(): void
    {
        StorefrontPageAssignBag::replace([
            'storefront_offers' => [['product_id' => 1]],
            'storefront_category' => ['name' => 'Robes'],
            'page_title' => 'Catalog',
            'storefront_offer' => ['product_id' => 99], // not allowed — must be dropped
        ]);

        $bag = StorefrontPageAssignBag::pull();
        self::assertSame([['product_id' => 1]], $bag['storefront_offers']);
        self::assertSame(['name' => 'Robes'], $bag['storefront_category']);
        self::assertSame('Catalog', $bag['page_title']);
        self::assertArrayNotHasKey('storefront_offer', $bag);

        $template = (new \ReflectionClass(Template::class))->newInstanceWithoutConstructor();
        $template->setData('storefront_offers', [['product_id' => 1]]);
        $template->unsetData();
        self::assertSame(
            [['product_id' => 1]],
            StorefrontPageAssignBag::coalesce($template->getData('storefront_offers'), 'storefront_offers', []),
        );
    }

    public function testWidgetCardSetDataDoesNotAutoPublishPageAssigns(): void
    {
        StorefrontPageAssignBag::replace([
            'storefront_offers' => [['product_id' => 1]],
        ]);
        $template = (new \ReflectionClass(Template::class))->newInstanceWithoutConstructor();
        $template->setData('storefront_offer', ['product_id' => 84, 'name' => 'Card']);
        self::assertSame([['product_id' => 1]], StorefrontPageAssignBag::get('storefront_offers'));
        self::assertNull(StorefrontPageAssignBag::get('storefront_offer'));
    }

    public function testOfferSeedSurvivesUnsetDataViaOfferResolverNotPageAssignBag(): void
    {
        $offer = ['product_id' => 83, 'global_offer_uuid' => 'pdp', 'name' => 'PDP'];
        StorefrontOfferResolver::rememberResolvedOffer($offer);
        $template = (new \ReflectionClass(Template::class))->newInstanceWithoutConstructor();
        $template->setData('storefront_offer', $offer);
        $template->unsetData();
        self::assertSame($offer, StorefrontOfferResolver::resolve($template));
        self::assertNull(StorefrontPageAssignBag::get('storefront_offer'));
    }

    public function testDetailCatalogControllersPublishBagKey(): void
    {
        $detail = (string)file_get_contents(dirname(__DIR__, 3) . '/Controller/Frontend/Detail.php');
        $catalog = (string)file_get_contents(dirname(__DIR__, 3) . '/Controller/Frontend/Catalog.php');
        $category = (string)file_get_contents(dirname(__DIR__, 3) . '/Controller/Frontend/Category.php');
        self::assertStringContainsString('StorefrontPageAssignBag::replace', $detail);
        self::assertStringContainsString('StorefrontPageAssignBag::replace', $catalog);
        self::assertStringContainsString('StorefrontPageAssignBag::replace', $category);
        self::assertSame('product.storefront.page_assigns.v1', StorefrontPageAssignBag::BAG_KEY);
    }
}
