<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Product\Service\StorefrontPdpShelfDeferral;

final class StorefrontPdpShelfDeferralContractTest extends TestCase
{
    private ?Context $previousContext = null;

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

    public function testEnableGatesDeferralOnlyWhenNotPreview(): void
    {
        self::assertFalse(StorefrontPdpShelfDeferral::isEnabled());
        self::assertFalse(StorefrontPdpShelfDeferral::shouldDeferCardAssembly(false));

        StorefrontPdpShelfDeferral::enableForRequest();
        self::assertTrue(StorefrontPdpShelfDeferral::isEnabled());
        self::assertTrue(RequestContext::get(StorefrontPdpShelfDeferral::BAG_KEY));
        self::assertTrue(StorefrontPdpShelfDeferral::shouldDeferCardAssembly(false));
        self::assertFalse(StorefrontPdpShelfDeferral::shouldDeferCardAssembly(true));
    }

    public function testDetailControllerEnablesDeferralAfterSeedRemember(): void
    {
        $detail = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Frontend/Detail.php',
        );
        self::assertStringContainsString('StorefrontPdpShelfDeferral::enableForRequest()', $detail);
        self::assertStringContainsString('StorefrontOfferResolver::rememberResolvedOffer($displayOffer)', $detail);
        $rememberPos = strpos($detail, 'StorefrontOfferResolver::rememberResolvedOffer($displayOffer)');
        $deferPos = strpos($detail, 'StorefrontPdpShelfDeferral::enableForRequest()');
        self::assertNotFalse($rememberPos);
        self::assertNotFalse($deferPos);
        self::assertGreaterThan($rememberPos, $deferPos);
    }

    public function testShelfTemplatesEmitHydrateShellWhenDeferred(): void
    {
        $crossSell = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/cross-sell.phtml',
        );
        self::assertStringContainsString('StorefrontPdpShelfDeferral::shouldDeferCardAssembly', $crossSell);
        self::assertStringContainsString('data-testid="cross-sell-deferred"', $crossSell);
        self::assertStringContainsString('data-hydrate-operation="bundleCards"', $crossSell);
        self::assertStringContainsString('data-weline-hydrate="1"', $crossSell);
        self::assertStringContainsString('if (count($products) < 2) {', $crossSell);
        self::assertStringContainsString('data-testid="cross-sell-empty"', $crossSell);

        $yml = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/you-may-like.phtml',
        );
        self::assertStringContainsString('data-testid="you-may-like-deferred"', $yml);
        self::assertStringContainsString('data-hydrate-operation="youMayLikeCards"', $yml);
        self::assertStringContainsString('youMayLikeCards', $yml);
    }
}
