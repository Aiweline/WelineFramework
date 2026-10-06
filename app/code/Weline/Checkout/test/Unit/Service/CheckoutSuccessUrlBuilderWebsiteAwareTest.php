<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Checkout\Service\CheckoutSuccessUrlBuilder;
use Weline\Framework\Http\Url;
use Weline\Payment\Service\PaymentStorefrontLandingUrlService;

/**
 * Stub storefront URL service for website-aware success URL building.
 */
final class CheckoutSuccessUrlBuilderWebsiteAwareTestStorefront extends PaymentStorefrontLandingUrlService
{
    public function __construct(
        private readonly string $baseUrl,
    ) {
    }

    public function resolveStorefrontBaseUrl(
        int $websiteId = 0,
        string $frozenBaseUrl = '',
        string $hintLandingUrl = '',
    ): string {
        return $this->baseUrl;
    }
}

final class CheckoutSuccessUrlBuilderWebsiteAwareTest extends TestCase
{
    public function testBuildForOrdersUsesStorefrontBaseWhenWebsiteIdGiven(): void
    {
        $url = $this->createMock(Url::class);
        $url->expects(self::never())->method('getUrl');

        $builder = new CheckoutSuccessUrlBuilder(
            $url,
            new CheckoutSuccessUrlBuilderWebsiteAwareTestStorefront(
                'https://p05113ef3.test.weline.com/daocharms',
            ),
        );
        $built = $builder->buildForOrders(['ord-1'], ['source' => 'payment_return'], 7);

        self::assertSame(
            'https://p05113ef3.test.weline.com/daocharms/checkout/success?source=payment_return&order_uuid=ord-1',
            $built,
        );
    }

    public function testBuildExpressReviewFallsBackToUrlHelperWithoutBase(): void
    {
        $url = $this->createMock(Url::class);
        $url->method('getUrl')->willReturn('http://shop.test/checkout/express-review?x=1');

        $builder = new CheckoutSuccessUrlBuilder(
            $url,
            new CheckoutSuccessUrlBuilderWebsiteAwareTestStorefront(''),
        );
        $built = $builder->buildExpressReview(['x' => 1], 3);

        self::assertSame('http://shop.test/checkout/express-review?x=1', $built);
    }

    public function testBuilderSourceAcceptsWebsiteId(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Service/CheckoutSuccessUrlBuilder.php',
        );
        self::assertStringContainsString('?int $websiteId = null', $src);
        self::assertStringContainsString('PaymentStorefrontLandingUrlService', $src);
    }
}
