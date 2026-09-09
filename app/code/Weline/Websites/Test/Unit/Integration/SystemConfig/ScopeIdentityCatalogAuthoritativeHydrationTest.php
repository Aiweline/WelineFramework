<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Integration\SystemConfig;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Websites\Api\Catalog\Data\StoreSummary;
use Weline\Websites\Api\Catalog\Data\WebsiteSummary;
use Weline\Websites\Api\Catalog\SalesChannelCatalogInterface;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;
use Weline\Websites\Api\Catalog\WebsiteCatalogInterface;
use Weline\Websites\Integration\SystemConfig\ScopeIdentityCatalog;

final class ScopeIdentityCatalogAuthoritativeHydrationTest extends TestCase
{
    public function testHydratesUnresolvedWebsiteIdZeroForNonDefaultWebsiteCode(): void
    {
        $catalog = $this->catalog(
            [new WebsiteSummary(5, 'Shop', 'shop', 'https://shop.example/')],
            [new StoreSummary(11, 5, 'default', 'Default', 'normal', true, true, 'active', null)],
        );

        $authoritative = $catalog->authoritativeIdentity(
            ScopeIdentity::store(0, 'shop', 'default', ScopeIdentity::MODE_NORMAL),
        );

        self::assertSame(5, $authoritative->websiteId);
        self::assertSame('shop', $authoritative->websiteCode);
        self::assertSame('default', $authoritative->storeCode);
        self::assertSame(ScopeIdentity::KIND_STORE, $authoritative->scopeKind);
    }

    public function testDefaultWebsiteIdZeroStillRequiresExactMatch(): void
    {
        $catalog = $this->catalog(
            [new WebsiteSummary(0, 'Default', 'default', 'https://example/')],
            [new StoreSummary(0, 0, 'default', 'Default', 'normal', true, true, 'active', null)],
        );

        $authoritative = $catalog->authoritativeIdentity(
            ScopeIdentity::store(0, 'default', 'default', ScopeIdentity::MODE_NORMAL),
        );
        self::assertSame(0, $authoritative->websiteId);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('system_config_scope_claim_identity_mismatch');
        $catalog->authoritativeIdentity(
            ScopeIdentity::store(9, 'default', 'default', ScopeIdentity::MODE_NORMAL),
        );
    }

    public function testRejectsMismatchedNonZeroWebsiteIdClaim(): void
    {
        $catalog = $this->catalog(
            [new WebsiteSummary(5, 'Shop', 'shop', 'https://shop.example/')],
            [new StoreSummary(11, 5, 'default', 'Default', 'normal', true, true, 'active', null)],
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('system_config_scope_claim_identity_mismatch');
        $catalog->authoritativeIdentity(
            ScopeIdentity::store(7, 'shop', 'default', ScopeIdentity::MODE_NORMAL),
        );
    }

    /**
     * @param list<WebsiteSummary> $websites
     * @param list<StoreSummary> $stores
     */
    private function catalog(array $websites, array $stores): ScopeIdentityCatalog
    {
        $websiteCatalog = $this->createMock(WebsiteCatalogInterface::class);
        $websiteCatalog->method('all')->willReturn($websites);

        $storeCatalog = $this->createMock(StoreCatalogInterface::class);
        $storeCatalog->method('byCode')->willReturnCallback(
            static function (int $websiteId, string $code) use ($stores): ?StoreSummary {
                foreach ($stores as $store) {
                    if ($store->websiteId === $websiteId && $store->code === $code) {
                        return $store;
                    }
                }

                return null;
            },
        );

        $channelCatalog = $this->createMock(SalesChannelCatalogInterface::class);
        $channelCatalog->method('byCode')->willReturn(null);

        return new ScopeIdentityCatalog($websiteCatalog, $storeCatalog, $channelCatalog);
    }
}
