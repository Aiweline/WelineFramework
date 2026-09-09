<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Adapter;

use PHPUnit\Framework\TestCase;

final class GoogleSearchConsoleDiscoverChannelsContractTest extends TestCase
{
    public function testGoogleFieldsDeclareDiscoverAndDistributionChannels(): void
    {
        $root = dirname(__DIR__, 4);
        $src = (string)file_get_contents($root . '/Service/Adapter/GoogleSearchConsoleAdapter.php');

        self::assertStringContainsString("'key' => 'enable_discover_stats'", $src);
        self::assertStringContainsString("'key' => 'enable_google_news_stats'", $src);
        self::assertStringContainsString("'type' => 'checkbox'", $src);
        self::assertStringContainsString("'key' => '__section_discover'", $src);
        self::assertStringContainsString("'key' => '__section_channels'", $src);
        self::assertStringContainsString('为什么配置', $src);
        self::assertStringContainsString('不会自动分发', $src);
        self::assertStringContainsString('信任分', $src);
        self::assertStringContainsString("'type' => 'section'", $src);
        self::assertStringContainsString("'key' => 'youtube_channel_url'", $src);
        self::assertStringContainsString("'key' => 'x_profile_url'", $src);
        self::assertStringContainsString("'key' => 'instagram_profile_url'", $src);
        self::assertStringContainsString("'key' => 'tiktok_profile_url'", $src);
        self::assertStringContainsString("'key' => 'linkedin_profile_url'", $src);
        self::assertStringContainsString("'group' => 'channels'", $src);
    }

    public function testStatsAndCapabilityPreserveDiscoverContracts(): void
    {
        $root = dirname(__DIR__, 4);
        $capabilitySrc = (string)file_get_contents($root . '/Service/SeoPlatformCapabilityService.php');
        self::assertStringContainsString("'section'", $capabilitySrc);
        self::assertStringContainsString("'group'", $capabilitySrc);

        $adapterSrc = (string)file_get_contents($root . '/Adapter/GoogleSitemapAdapter.php');
        self::assertStringContainsString("'discover'", $adapterSrc);
        self::assertStringContainsString('enable_discover_stats', $adapterSrc);
        self::assertStringContainsString('enable_google_news_stats', $adapterSrc);
        self::assertStringContainsString('extractDistributionChannels', $adapterSrc);
        self::assertStringContainsString("'googleNews'", $adapterSrc);
        self::assertStringContainsString('platform_property', $adapterSrc);
    }

    public function testFormRendersConfigSectionsAndChannelGrid(): void
    {
        $root = dirname(__DIR__, 4);
        $template = (string)file_get_contents($root . '/view/templates/Backend/Account/form.phtml');
        self::assertStringContainsString("type === 'section'", $template);
        self::assertStringContainsString('seo-config-section', $template);
        self::assertStringContainsString('seo-config-channel-grid', $template);
        self::assertStringContainsString('启用 Discover 统计', $template);
        self::assertStringContainsString('平台属性', $template);

        $css = (string)file_get_contents($root . '/view/statics/css/seo-admin.css');
        self::assertStringContainsString('.seo-config-section', $css);
        self::assertStringContainsString('.seo-config-channel-grid', $css);
        self::assertStringContainsString('var(--seo-border)', $css);

        $proto = $root . '/view/statics/prototype/gsc-channels-ui.html';
        self::assertFileExists($proto);
        $protoSrc = (string)file_get_contents($proto);
        self::assertStringContainsString('?variant=', $protoSrc);
        self::assertStringContainsString('tplA', $protoSrc);
        self::assertStringContainsString('PROTOTYPE', $protoSrc);
    }
}
