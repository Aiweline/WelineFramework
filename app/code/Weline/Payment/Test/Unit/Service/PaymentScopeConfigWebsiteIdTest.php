<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Service\PaymentScopeConfigService;
use Weline\Payment\Service\PaymentStorefrontLandingUrlService;

final class PaymentScopeConfigWebsiteIdTestStorefront extends PaymentStorefrontLandingUrlService
{
    public function __construct(
        private readonly string $code,
    ) {
    }

    public function loadWebsiteCode(int $websiteId): string
    {
        return $this->code;
    }
}

final class PaymentScopeConfigWebsiteIdTest extends TestCase
{
    public function testWebsiteIdResolvesToWebsiteCodeScope(): void
    {
        $service = new PaymentScopeConfigService(
            null,
            null,
            null,
            new PaymentScopeConfigWebsiteIdTestStorefront('daocharms'),
        );
        $resolved = $service->resolveScope(['website_id' => 7]);

        self::assertSame('daocharms.default.default', $resolved['scope']);
        self::assertSame(7, $resolved['website_id']);
    }

    public function testExplicitScopeStillWins(): void
    {
        $service = new PaymentScopeConfigService();
        $resolved = $service->resolveScope([
            'scope' => 'custom.store.channel',
            'website_id' => 7,
        ]);

        self::assertSame('custom.store.channel', $resolved['scope']);
        self::assertSame(7, $resolved['website_id']);
    }

    public function testWebsiteCodeDirectlyBuildsScope(): void
    {
        $service = new PaymentScopeConfigService();
        $resolved = $service->resolveScope([
            'website_code' => 'daocharms',
            'website_id' => 7,
        ]);

        self::assertSame('daocharms.default.default', $resolved['scope']);
    }
}
