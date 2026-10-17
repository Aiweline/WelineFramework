<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Adapter;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllSeoAdapterContractsPass().
 */
final class SeoAdapterContractsTest extends TestCase
{
    public function testAllSeoAdapterContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'GoogleSearchConsoleDiscoverChannels::testGoogleFieldsDeclareDiscoverAndDistributionChannels' => function () {
            $this->assert_GoogleSearchConsoleDiscoverChannels_testGoogleFieldsDeclareDiscoverAndDistributionChannels();
        },
            'GoogleSearchConsoleDiscoverChannels::testStatsAndCapabilityPreserveDiscoverContracts' => function () {
            $this->assert_GoogleSearchConsoleDiscoverChannels_testStatsAndCapabilityPreserveDiscoverContracts();
        },
            'GoogleSearchConsoleDiscoverChannels::testFormRendersConfigSectionsAndChannelGrid' => function () {
            $this->assert_GoogleSearchConsoleDiscoverChannels_testFormRendersConfigSectionsAndChannelGrid();
        },
        ];
    }

private function assert_GoogleSearchConsoleDiscoverChannels_testGoogleFieldsDeclareDiscoverAndDistributionChannels(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Seo';
    $src = (string) file_get_contents($root . '/Service/Adapter/GoogleSearchConsoleAdapter.php');
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

private function assert_GoogleSearchConsoleDiscoverChannels_testStatsAndCapabilityPreserveDiscoverContracts(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Seo';
    $capabilitySrc = (string) file_get_contents($root . '/Service/SeoPlatformCapabilityService.php');
    self::assertStringContainsString("'section'", $capabilitySrc);
    self::assertStringContainsString("'group'", $capabilitySrc);
    $adapterSrc = (string) file_get_contents($root . '/Adapter/GoogleSitemapAdapter.php');
    self::assertStringContainsString("'discover'", $adapterSrc);
    self::assertStringContainsString('enable_discover_stats', $adapterSrc);
    self::assertStringContainsString('enable_google_news_stats', $adapterSrc);
    self::assertStringContainsString('extractDistributionChannels', $adapterSrc);
    self::assertStringContainsString("'googleNews'", $adapterSrc);
    self::assertStringContainsString('platform_property', $adapterSrc);
}

private function assert_GoogleSearchConsoleDiscoverChannels_testFormRendersConfigSectionsAndChannelGrid(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Seo';
    $template = (string) file_get_contents($root . '/view/templates/backend/Account/form.phtml');
    self::assertStringContainsString("type === 'section'", $template);
    self::assertStringContainsString('seo-config-section', $template);
    self::assertStringContainsString('seo-config-channel-grid', $template);
    self::assertStringContainsString('启用 Discover 统计', $template);
    self::assertStringContainsString('平台属性', $template);
    $css = (string) file_get_contents($root . '/view/statics/css/seo-admin.css');
    self::assertStringContainsString('.seo-config-section', $css);
    self::assertStringContainsString('.seo-config-channel-grid', $css);
    self::assertStringContainsString('var(--seo-border)', $css);
    $proto = $root . '/view/statics/prototype/gsc-channels-ui.html';
    self::assertFileExists($proto);
    $protoSrc = (string) file_get_contents($proto);
    self::assertStringContainsString('?variant=', $protoSrc);
    self::assertStringContainsString('tplA', $protoSrc);
    self::assertStringContainsString('PROTOTYPE', $protoSrc);
}
}
