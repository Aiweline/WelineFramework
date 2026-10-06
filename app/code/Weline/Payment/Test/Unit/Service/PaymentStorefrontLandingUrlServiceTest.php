<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Service\PaymentStorefrontLandingUrlService;

/**
 * Path-prefix mounts (e.g. /daocharms) must survive express→success rewrite.
 */
final class PaymentStorefrontLandingUrlServiceTest extends TestCase
{
    public function testExtractBaseFromDaocharmsExpressLanding(): void
    {
        $svc = new PaymentStorefrontLandingUrlService();
        $base = $svc->extractBaseFromLanding(
            'https://p05113ef3.test.weline.com/daocharms/checkout/express-review?transaction_no=PAY1',
        );
        self::assertSame('https://p05113ef3.test.weline.com/daocharms', $base);
    }

    public function testBuildCheckoutSuccessKeepsDaocharmsMount(): void
    {
        $svc = new PaymentStorefrontLandingUrlService();
        $url = $svc->buildAbsoluteRoute(
            'checkout/success',
            ['order_uuid' => 'ord-1', 'checkout_group_uuid' => 'grp-1'],
            '',
            'https://p05113ef3.test.weline.com/daocharms/checkout/express-review?transaction_no=PAY1',
        );
        self::assertStringStartsWith(
            'https://p05113ef3.test.weline.com/daocharms/checkout/success?',
            $url,
        );
        self::assertStringContainsString('order_uuid=ord-1', $url);
        self::assertStringNotContainsString('/daocharms/daocharms/', $url);
    }

    public function testEnsureLandingRebuildsHostRootSuccessUnderMount(): void
    {
        $svc = new PaymentStorefrontLandingUrlService();
        $fixed = $svc->ensureLandingUnderBase(
            '/checkout/success?order_uuid=ord-1',
            'https://p05113ef3.test.weline.com/daocharms',
        );
        self::assertSame(
            'https://p05113ef3.test.weline.com/daocharms/checkout/success?order_uuid=ord-1',
            $fixed,
        );
    }

    public function testEnsureLandingLeavesCorrectMountUntouched(): void
    {
        $svc = new PaymentStorefrontLandingUrlService();
        $ok = 'https://p05113ef3.test.weline.com/daocharms/checkout/success?order_uuid=ord-1';
        self::assertSame(
            $ok,
            $svc->ensureLandingUnderBase($ok, 'https://p05113ef3.test.weline.com/daocharms'),
        );
    }

    public function testPrefersHintOverProductionWebsiteFreeze(): void
    {
        $svc = new PaymentStorefrontLandingUrlService();
        $base = $svc->resolveStorefrontBaseUrl(
            158,
            'https://daocharms.com',
            'https://p05113ef3.test.weline.com/daocharms/checkout/express-review?t=1',
        );
        self::assertSame('https://p05113ef3.test.weline.com/daocharms', $base);
    }

    public function testNormalizeKeepsMountPath(): void
    {
        $svc = new PaymentStorefrontLandingUrlService();
        self::assertSame(
            'https://p05113ef3.test.weline.com/daocharms',
            $svc->normalizeBaseUrl('https://p05113ef3.test.weline.com/daocharms/'),
        );
    }

    public function testDefaultSiteHasNoMountPrefix(): void
    {
        $svc = new PaymentStorefrontLandingUrlService();
        $url = $svc->buildAbsoluteRoute(
            'checkout/success',
            ['order_uuid' => 'ord-2'],
            'https://p05113ef3.test.weline.com',
        );
        self::assertSame(
            'https://p05113ef3.test.weline.com/checkout/success?order_uuid=ord-2',
            $url,
        );
    }

    public function testStaleHostOnlyFreezeYieldsToMountedLiveBase(): void
    {
        $svc = new class extends PaymentStorefrontLandingUrlService {
            public function resolveBaseFromCurrentRequest(int $websiteId = 0): string
            {
                return 'https://p05113ef3.test.weline.com/daocharms';
            }
        };
        $base = $svc->resolveStorefrontBaseUrl(
            158,
            'https://p05113ef3.test.weline.com',
            '',
        );
        self::assertSame('https://p05113ef3.test.weline.com/daocharms', $base);
    }
}
