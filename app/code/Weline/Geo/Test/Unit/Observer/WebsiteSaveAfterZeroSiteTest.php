<?php

declare(strict_types=1);

namespace Weline\Geo\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\Event;
use Weline\Geo\Model\WebsiteProtocolConfig;
use Weline\Geo\Observer\WebsiteSaveAfter;

/** Plan coverage: ZERO02 and WEB01 extension failure/input semantics. */
final class WebsiteSaveAfterZeroSiteTest extends TestCase
{
    public function testZero02ExplicitWebsiteZeroIsPersistedAsDefaultSite(): void
    {
        $config = $this->createMock(WebsiteProtocolConfig::class);
        $config->expects(self::once())
            ->method('saveForWebsite')
            ->with(0, [
                'llms_enabled' => false,
                'feed_enabled' => true,
                'auto_push' => false,
                'feed_id' => 7,
                'llms_intro' => 'Default site AI policy',
            ])
            ->willReturnSelf();

        $observer = new WebsiteSaveAfter($config);
        $event = new Event('Weline_Websites::website_save_after', [
            'website_id' => 0,
            'post_data' => [
                'extensions' => [
                    'geo' => [
                        'llms_enabled' => '0',
                        'feed_enabled' => 'yes',
                        'auto_push' => 'off',
                        'feed_id' => '7',
                        'llms_intro' => 'Default site AI policy',
                    ],
                ],
            ],
        ]);

        $observer->execute($event);
    }

    public function testZero02MissingOrNullWebsiteDoesNotFallbackToDefaultSite(): void
    {
        foreach ([[], ['website_id' => null]] as $payload) {
            $config = $this->createMock(WebsiteProtocolConfig::class);
            $config->expects(self::never())->method('saveForWebsite');
            $observer = new WebsiteSaveAfter($config);
            $event = new Event('Weline_Websites::website_save_after', $payload + [
                'post_data' => ['extensions' => ['geo' => ['feed_id' => 1]]],
            ]);

            $observer->execute($event);
        }
    }

    public function testZero02NegativeWebsiteIsRejected(): void
    {
        $config = $this->createMock(WebsiteProtocolConfig::class);
        $config->expects(self::never())->method('saveForWebsite');
        $observer = new WebsiteSaveAfter($config);
        $event = new Event('Weline_Websites::website_save_after', [
            'website_id' => -1,
            'post_data' => ['extensions' => ['geo' => ['feed_id' => 1]]],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $observer->execute($event);
    }
}
