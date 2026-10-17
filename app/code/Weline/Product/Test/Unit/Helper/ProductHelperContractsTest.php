<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\View\Template;
use Weline\Product\Helper\StorefrontOfferResolver;
use Weline\Product\Helper\StorefrontPageAssignBag;
use Weline\Product\Helper\StorefrontPdpBuyBoxBag;

/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllProductHelperContractsPass().
 */
final class ProductHelperContractsTest extends TestCase
{
    public function testAllProductHelperContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'StorefrontPageAssignBag::testControllerPublishSurvivesTemplateUnsetData' => function () {
            $this->setUp_StorefrontPageAssignBag();
            try {
                $this->assert_StorefrontPageAssignBag_testControllerPublishSurvivesTemplateUnsetData();
            } finally {
                $this->tearDown_StorefrontPageAssignBag();
            }
        },
            'StorefrontPageAssignBag::testWidgetCardSetDataDoesNotAutoPublishPageAssigns' => function () {
            $this->setUp_StorefrontPageAssignBag();
            try {
                $this->assert_StorefrontPageAssignBag_testWidgetCardSetDataDoesNotAutoPublishPageAssigns();
            } finally {
                $this->tearDown_StorefrontPageAssignBag();
            }
        },
            'StorefrontPageAssignBag::testOfferSeedSurvivesUnsetDataViaOfferResolverNotPageAssignBag' => function () {
            $this->setUp_StorefrontPageAssignBag();
            try {
                $this->assert_StorefrontPageAssignBag_testOfferSeedSurvivesUnsetDataViaOfferResolverNotPageAssignBag();
            } finally {
                $this->tearDown_StorefrontPageAssignBag();
            }
        },
            'StorefrontPageAssignBag::testDetailCatalogControllersPublishBagKey' => function () {
            $this->setUp_StorefrontPageAssignBag();
            try {
                $this->assert_StorefrontPageAssignBag_testDetailCatalogControllersPublishBagKey();
            } finally {
                $this->tearDown_StorefrontPageAssignBag();
            }
        },
            'StorefrontPdpBuyBoxBag::testDetailFillsBuyBoxBeforeShellFetch' => function () {
            $this->setUp_StorefrontPdpBuyBoxBag();
            try {
                $this->assert_StorefrontPdpBuyBoxBag_testDetailFillsBuyBoxBeforeShellFetch();
            } finally {
                $this->tearDown_StorefrontPdpBuyBoxBag();
            }
        },
            'StorefrontPdpBuyBoxBag::testBagHasKeyDistinguishesMissFromEmptyString' => function () {
            $this->setUp_StorefrontPdpBuyBoxBag();
            try {
                $this->assert_StorefrontPdpBuyBoxBag_testBagHasKeyDistinguishesMissFromEmptyString();
            } finally {
                $this->tearDown_StorefrontPdpBuyBoxBag();
            }
        },
            'StorefrontPdpBuyBoxBag::testProductInfoReadsBuyBoxForHintReviewMoq' => function () {
            $this->setUp_StorefrontPdpBuyBoxBag();
            try {
                $this->assert_StorefrontPdpBuyBoxBag_testProductInfoReadsBuyBoxForHintReviewMoq();
            } finally {
                $this->tearDown_StorefrontPdpBuyBoxBag();
            }
        },
            'StorefrontPdpBuyBoxBag::testFillSourceUsesDefaultOriginNotPlanPackages' => function () {
            $this->setUp_StorefrontPdpBuyBoxBag();
            try {
                $this->assert_StorefrontPdpBuyBoxBag_testFillSourceUsesDefaultOriginNotPlanPackages();
            } finally {
                $this->tearDown_StorefrontPdpBuyBoxBag();
            }
        },
            'StorefrontRouteIdentityNoUrlReparse::testOfferResolverReadsContextNotUrl' => function () {
            $this->assert_StorefrontRouteIdentityNoUrlReparse_testOfferResolverReadsContextNotUrl();
        },
            'StorefrontRouteIdentityNoUrlReparse::testVariantResolverReadsContextNotUrl' => function () {
            $this->assert_StorefrontRouteIdentityNoUrlReparse_testVariantResolverReadsContextNotUrl();
        },
            'StorefrontRouteIdentityNoUrlReparse::testDetailCarriesResolvedIdentityIntoRequestContext' => function () {
            $this->assert_StorefrontRouteIdentityNoUrlReparse_testDetailCarriesResolvedIdentityIntoRequestContext();
        },
        ];
    }

private ?Context $previousContext_StorefrontPageAssignBag;

private function setUp_StorefrontPageAssignBag(): void
{
    $this->previousContext_StorefrontPageAssignBag = Context::getCurrent();
    Context::enter(new Context());
    RequestContext::setId('product-page-assign-bag-test');
    StorefrontPageAssignBag::reset();
}

private function tearDown_StorefrontPageAssignBag(): void
{
    StorefrontPageAssignBag::reset();
    Context::leave();
    if ($this->previousContext_StorefrontPageAssignBag !== null) {
        Context::enter($this->previousContext_StorefrontPageAssignBag);
    }
}

private function assert_StorefrontPageAssignBag_testControllerPublishSurvivesTemplateUnsetData(): void
{
    StorefrontPageAssignBag::replace(['storefront_offers' => [['product_id' => 1]], 'storefront_category' => ['name' => 'Robes'], 'page_title' => 'Catalog', 'storefront_offer' => ['product_id' => 99]]);
    $bag = StorefrontPageAssignBag::pull();
    self::assertSame([['product_id' => 1]], $bag['storefront_offers']);
    self::assertSame(['name' => 'Robes'], $bag['storefront_category']);
    self::assertSame('Catalog', $bag['page_title']);
    self::assertArrayNotHasKey('storefront_offer', $bag);
    $template = (new \ReflectionClass(Template::class))->newInstanceWithoutConstructor();
    $template->setData('storefront_offers', [['product_id' => 1]]);
    $template->unsetData();
    self::assertSame([['product_id' => 1]], StorefrontPageAssignBag::coalesce($template->getData('storefront_offers'), 'storefront_offers', []));
}

private function assert_StorefrontPageAssignBag_testWidgetCardSetDataDoesNotAutoPublishPageAssigns(): void
{
    StorefrontPageAssignBag::replace(['storefront_offers' => [['product_id' => 1]]]);
    $template = (new \ReflectionClass(Template::class))->newInstanceWithoutConstructor();
    $template->setData('storefront_offer', ['product_id' => 84, 'name' => 'Card']);
    self::assertSame([['product_id' => 1]], StorefrontPageAssignBag::get('storefront_offers'));
    self::assertNull(StorefrontPageAssignBag::get('storefront_offer'));
}

private function assert_StorefrontPageAssignBag_testOfferSeedSurvivesUnsetDataViaOfferResolverNotPageAssignBag(): void
{
    $offer = ['product_id' => 83, 'global_offer_uuid' => 'pdp', 'name' => 'PDP'];
    StorefrontOfferResolver::rememberResolvedOffer($offer);
    $template = (new \ReflectionClass(Template::class))->newInstanceWithoutConstructor();
    $template->setData('storefront_offer', $offer);
    $template->unsetData();
    self::assertSame($offer, StorefrontOfferResolver::resolve($template));
    self::assertNull(StorefrontPageAssignBag::get('storefront_offer'));
}

private function assert_StorefrontPageAssignBag_testDetailCatalogControllersPublishBagKey(): void
{
    $detail = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Controller/Frontend/Detail.php');
    $catalog = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Controller/Frontend/Catalog.php');
    $category = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Controller/Frontend/Category.php');
    self::assertStringContainsString('StorefrontPageAssignBag::replace', $detail);
    self::assertStringContainsString('StorefrontPageAssignBag::replace', $catalog);
    self::assertStringContainsString('StorefrontPageAssignBag::replace', $category);
    self::assertSame('product.storefront.page_assigns.v1', StorefrontPageAssignBag::BAG_KEY);
}

private ?Context $previousContext_StorefrontPdpBuyBoxBag;

private function setUp_StorefrontPdpBuyBoxBag(): void
{
    $this->previousContext_StorefrontPdpBuyBoxBag = Context::getCurrent();
    Context::enter(new Context());
    RequestContext::setId('product-pdp-buy-box-bag-test');
    StorefrontPdpBuyBoxBag::reset();
}

private function tearDown_StorefrontPdpBuyBoxBag(): void
{
    StorefrontPdpBuyBoxBag::reset();
    Context::leave();
    if ($this->previousContext_StorefrontPdpBuyBoxBag !== null) {
        Context::enter($this->previousContext_StorefrontPdpBuyBoxBag);
    }
}

private function assert_StorefrontPdpBuyBoxBag_testDetailFillsBuyBoxBeforeShellFetch(): void
{
    $controller = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Controller/Frontend/Detail.php');
    self::assertStringContainsString('StorefrontPdpBuyBoxBag::fillFromDisplayOffer($displayOffer)', $controller);
    $fillPos = strpos($controller, 'StorefrontPdpBuyBoxBag::fillFromDisplayOffer');
    $fetchPos = strpos($controller, "fetch('Weline_Product::templates/frontend/catalog/detail.phtml')");
    self::assertNotFalse($fillPos);
    self::assertNotFalse($fetchPos);
    self::assertLessThan($fetchPos, $fillPos);
}

private function assert_StorefrontPdpBuyBoxBag_testBagHasKeyDistinguishesMissFromEmptyString(): void
{
    self::assertFalse(StorefrontPdpBuyBoxBag::hasKey('origin_country'));
    RequestContext::set(StorefrontPdpBuyBoxBag::BAG_KEY, ['origin_country' => '', 'express_methods' => []]);
    self::assertTrue(StorefrontPdpBuyBoxBag::hasKey('origin_country'));
    self::assertSame('', StorefrontPdpBuyBoxBag::get('origin_country'));
    self::assertTrue(StorefrontPdpBuyBoxBag::hasKey('express_methods'));
    self::assertSame([], StorefrontPdpBuyBoxBag::get('express_methods'));
}

private function assert_StorefrontPdpBuyBoxBag_testProductInfoReadsBuyBoxForHintReviewMoq(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/templates/frontend/widgets/product-info.phtml');
    self::assertStringContainsString('StorefrontPdpBuyBoxBag::hasKey(\'shipping_hint\')', $template);
    self::assertStringContainsString('StorefrontPdpBuyBoxBag::hasKey(\'review\')', $template);
    self::assertStringContainsString('StorefrontPdpBuyBoxBag::hasKey(\'selling\')', $template);
}

private function assert_StorefrontPdpBuyBoxBag_testFillSourceUsesDefaultOriginNotPlanPackages(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Helper/StorefrontPdpBuyBoxBag.php');
    self::assertStringContainsString('resolveDefaultOriginCountry', $src);
    self::assertStringNotContainsString('resolveForOffer', $src);
    self::assertStringNotContainsString('planPackages', $src);
    self::assertStringNotContainsString('MembershipStatusProjection', $src);
}

private function assert_StorefrontRouteIdentityNoUrlReparse_testOfferResolverReadsContextNotUrl(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Helper/StorefrontOfferResolver.php');
    self::assertStringContainsString("Context::current()", $source);
    self::assertStringContainsString("query('slug')", $source);
    self::assertStringContainsString("query('id')", $source);
    self::assertStringNotContainsString('StorefrontProductRouteContext', $source);
    self::assertStringNotContainsString('theme_public_route', $source);
    self::assertStringNotContainsString('REQUEST_URI', $source);
    self::assertStringNotContainsString('parse_url', $source);
}

private function assert_StorefrontRouteIdentityNoUrlReparse_testVariantResolverReadsContextNotUrl(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Helper/StorefrontVariantContextResolver.php');
    self::assertStringContainsString("Context::current()", $source);
    self::assertStringNotContainsString('StorefrontProductRouteContext', $source);
    self::assertStringNotContainsString('theme_public_route', $source);
    self::assertStringNotContainsString('preg_match', $source);
}

private function assert_StorefrontRouteIdentityNoUrlReparse_testDetailCarriesResolvedIdentityIntoRequestContext(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Controller/Frontend/Detail.php');
    self::assertStringContainsString('carryResolvedIdentity', $source);
    self::assertStringContainsString("set('input.query.id'", $source);
    self::assertStringContainsString("set('input.query.slug'", $source);
    self::assertStringNotContainsString('StorefrontProductRouteContext', $source);
}
}
