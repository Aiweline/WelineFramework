<?php

declare(strict_types=1);

namespace WeShop\Social\Test\Unit\Extends\Module\Weline_Framework\Query;

use PHPUnit\Framework\TestCase;
use WeShop\Social\Extends\Module\Weline_Framework\Query\SocialQueryProvider;
use WeShop\Social\Model\SocialShare;
use WeShop\Social\Service\SocialService;

class SocialQueryProviderTest extends TestCase
{
    public function testExecuteRecordsShareThroughService(): void
    {
        $share = $this->getMockBuilder(SocialShare::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getData'])
            ->getMock();
        $share->method('getId')->willReturn(77);
        $share->method('getData')->willReturnMap([
            [SocialShare::schema_fields_CUSTOMER_ID, null, 6],
            [SocialShare::schema_fields_PRODUCT_ID, null, 88],
            [SocialShare::schema_fields_PLATFORM, null, 'x'],
        ]);

        $service = $this->createMock(SocialService::class);
        $service->expects($this->once())
            ->method('recordShare')
            ->with([
                'customer_id' => 6,
                'product_id' => 88,
                'platform' => 'twitter',
            ])
            ->willReturn($share);

        $provider = new SocialQueryProvider($service);

        $result = $provider->execute('recordShare', [
            'customer_id' => 6,
            'product_id' => 88,
            'platform' => 'twitter',
        ]);

        $this->assertSame(77, $result['share_id']);
        $this->assertSame('x', $result[SocialShare::schema_fields_PLATFORM]);
    }

    public function testExecuteReturnsFooterLinksThroughService(): void
    {
        $service = $this->createMock(SocialService::class);
        $service->expects($this->once())
            ->method('getFooterSocialLinks')
            ->with(['facebook' => 'https://facebook.example/store'])
            ->willReturn([
                ['platform' => 'facebook', 'url' => 'https://facebook.example/store'],
            ]);

        $provider = new SocialQueryProvider($service);

        $result = $provider->execute('getFooterSocialLinks', [
            'context' => ['facebook' => 'https://facebook.example/store'],
        ]);

        $this->assertSame('facebook', $result[0]['platform']);
    }
}
