<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Product\Helper\StorefrontPdpBuyBoxBag;

final class StorefrontPdpBuyBoxBagContractTest extends TestCase
{
    private ?Context $previousContext;

    protected function setUp(): void
    {
        $this->previousContext = Context::getCurrent();
        Context::enter(new Context());
        RequestContext::setId('product-pdp-buy-box-bag-test');
        StorefrontPdpBuyBoxBag::reset();
    }

    protected function tearDown(): void
    {
        StorefrontPdpBuyBoxBag::reset();
        Context::leave();
        if ($this->previousContext !== null) {
            Context::enter($this->previousContext);
        }
    }

    public function testDetailFillsBuyBoxBeforeShellFetch(): void
    {
        $controller = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Frontend/Detail.php',
        );
        self::assertStringContainsString('StorefrontPdpBuyBoxBag::fillFromDisplayOffer($displayOffer)', $controller);
        $fillPos = strpos($controller, 'StorefrontPdpBuyBoxBag::fillFromDisplayOffer');
        $fetchPos = strpos($controller, "fetch('Weline_Product::templates/frontend/catalog/detail-shell.phtml')");
        self::assertNotFalse($fillPos);
        self::assertNotFalse($fetchPos);
        self::assertLessThan($fetchPos, $fillPos);
    }

    public function testBagHasKeyDistinguishesMissFromEmptyString(): void
    {
        self::assertFalse(StorefrontPdpBuyBoxBag::hasKey('origin_country'));
        RequestContext::set(StorefrontPdpBuyBoxBag::BAG_KEY, [
            'origin_country' => '',
            'express_methods' => [],
        ]);
        self::assertTrue(StorefrontPdpBuyBoxBag::hasKey('origin_country'));
        self::assertSame('', StorefrontPdpBuyBoxBag::get('origin_country'));
        self::assertTrue(StorefrontPdpBuyBoxBag::hasKey('express_methods'));
        self::assertSame([], StorefrontPdpBuyBoxBag::get('express_methods'));
    }

    public function testProductInfoReadsBuyBoxForHintReviewMoq(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/product-info.phtml',
        );
        self::assertStringContainsString('StorefrontPdpBuyBoxBag::hasKey(\'shipping_hint\')', $template);
        self::assertStringContainsString('StorefrontPdpBuyBoxBag::hasKey(\'review\')', $template);
        self::assertStringContainsString('StorefrontPdpBuyBoxBag::hasKey(\'selling\')', $template);
    }

    public function testFillSourceUsesDefaultOriginNotPlanPackages(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Helper/StorefrontPdpBuyBoxBag.php',
        );
        self::assertStringContainsString('resolveDefaultOriginCountry', $src);
        self::assertStringNotContainsString('resolveForOffer', $src);
        self::assertStringNotContainsString('planPackages', $src);
        self::assertStringNotContainsString('MembershipStatusProjection', $src);
    }
}
