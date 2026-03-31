<?php

declare(strict_types=1);

namespace WeShop\Social\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Social\Model\SocialShare;
use WeShop\Social\Service\SocialService;
use Weline\Framework\Event\EventsManager;

class SocialServiceTest extends TestCase
{
    public function testRecordShareNormalizesPlatformAndDispatchesEvents(): void
    {
        $data = [];
        $share = $this->getMockBuilder(SocialShare::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['clearData', 'setData', 'save', 'getId', 'getData'])
            ->getMock();
        $share->expects($this->once())->method('clearData')->willReturnSelf();
        $share->expects($this->exactly(3))
            ->method('setData')
            ->willReturnCallback(static function (string $key, mixed $value) use (&$data, $share): SocialShare {
                $data[$key] = $value;
                return $share;
            });
        $share->expects($this->once())->method('save')->willReturn(true);
        $share->method('getId')->willReturn(55);
        $share->method('getData')->willReturnCallback(static function (string $key, mixed $default = null) use (&$data): mixed {
            return $data[$key] ?? $default;
        });

        $events = $this->createMock(EventsManager::class);
        $events->expects($this->exactly(2))
            ->method('dispatch')
            ->with(
                $this->logicalOr(
                    $this->equalTo('WeShop_Social::share_before'),
                    $this->equalTo('WeShop_Social::share_after')
                ),
                $this->isType('array')
            );

        $service = new SocialService($share, $events);
        $result = $service->recordShare([
            'customer_id' => 9,
            'product_id' => 88,
            'platform' => 'Twitter',
        ]);

        $this->assertInstanceOf(SocialShare::class, $result);
        $this->assertSame(9, $data[SocialShare::schema_fields_CUSTOMER_ID]);
        $this->assertSame(88, $data[SocialShare::schema_fields_PRODUCT_ID]);
        $this->assertSame('x', $data[SocialShare::schema_fields_PLATFORM]);
    }

    public function testRecordShareRejectsMissingPlatform(): void
    {
        $service = new SocialService(
            $this->getMockBuilder(SocialShare::class)->disableOriginalConstructor()->getMock(),
            $this->createMock(EventsManager::class)
        );

        $this->expectException(\InvalidArgumentException::class);
        $service->recordShare([
            'customer_id' => 1,
            'product_id' => 2,
        ]);
    }

    public function testGetFooterSocialLinksUsesConfiguredValues(): void
    {
        $service = new class extends SocialService {
            protected function readConfigValue(string $configPath): string
            {
                return match ($configPath) {
                    'social.links.facebook' => 'https://facebook.example/store',
                    'social.links.instagram' => 'https://instagram.example/store',
                    default => '',
                };
            }
        };

        $links = $service->getFooterSocialLinks();

        $this->assertCount(2, $links);
        $this->assertSame('facebook', $links[0]['platform']);
        $this->assertSame('https://facebook.example/store', $links[0]['url']);
        $this->assertSame('instagram', $links[1]['platform']);
    }

    public function testGetProductShareUrlsBuildsPublicShareTargets(): void
    {
        $service = new SocialService();

        $links = $service->getProductShareUrls(
            'https://shop.example/product/travel-bag',
            'Travel Bag',
            ['facebook', 'twitter', 'whatsapp']
        );

        $this->assertCount(3, $links);
        $this->assertSame('facebook', $links[0]['platform']);
        $this->assertStringContainsString('facebook.com', $links[0]['url']);
        $this->assertSame('x', $links[1]['platform']);
        $this->assertStringContainsString('twitter.com', $links[1]['url']);
        $this->assertSame('whatsapp', $links[2]['platform']);
    }
}
