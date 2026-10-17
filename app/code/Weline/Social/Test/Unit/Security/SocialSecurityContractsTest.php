<?php

declare(strict_types=1);

namespace Weline\Social\Test\Unit\Security;

use PHPUnit\Framework\TestCase;
use Weline\Social\Extends\Module\Weline_Framework\Security\Csp\SocialPlatformsCsp;
use Weline\Social\Platform\Messaging\WechatProvider;
use Weline\Social\Platform\Social\TiktokProvider;
use Weline\Social\Platform\Social\XProvider;
use Weline\Social\Platform\Video\YoutubeProvider;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllSocialSecurityContractsPass().
 */
final class SocialSecurityContractsTest extends TestCase
{
    public function testAllSocialSecurityContractsPass(): void
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
            'SocialPlatformCspDirectives::testBuiltInPlatformsDeclareCspDirectives' => function () {
            $this->assert_SocialPlatformCspDirectives_testBuiltInPlatformsDeclareCspDirectives();
        },
            'SocialPlatformCspDirectives::testSocialPlatformsCspAggregatesProviders' => function () {
            $this->assert_SocialPlatformCspDirectives_testSocialPlatformsCspAggregatesProviders();
        },
            'SocialPlatformCspDirectives::testInterfaceDeclaresCspDirectives' => function () {
            $this->assert_SocialPlatformCspDirectives_testInterfaceDeclaresCspDirectives();
        },
        ];
    }

private function assert_SocialPlatformCspDirectives_testBuiltInPlatformsDeclareCspDirectives(): void
{
    self::assertContains('https://open.weixin.qq.com', (new WechatProvider())->cspDirectives()['script-src'] ?? []);
    self::assertContains('https://platform.twitter.com', (new XProvider())->cspDirectives()['script-src'] ?? []);
    self::assertContains('https://www.tiktok.com', (new TiktokProvider())->cspDirectives()['frame-src'] ?? []);
    self::assertContains('https://www.youtube.com', (new YoutubeProvider())->cspDirectives()['frame-src'] ?? []);
}

private function assert_SocialPlatformCspDirectives_testSocialPlatformsCspAggregatesProviders(): void
{
    $contribution = (new SocialPlatformsCsp(static fn(): array => [new WechatProvider(), new XProvider(), new TiktokProvider(), new YoutubeProvider()]))->contribution();
    self::assertContains('https://open.weixin.qq.com', $contribution->directives['script-src'] ?? []);
    self::assertContains('https://x.com', $contribution->directives['frame-src'] ?? []);
    self::assertContains('https://api.tiktok.com', $contribution->directives['connect-src'] ?? []);
    self::assertContains('https://www.youtube-nocookie.com', $contribution->directives['frame-src'] ?? []);
}

private function assert_SocialPlatformCspDirectives_testInterfaceDeclaresCspDirectives(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Social' . '/Interface/SocialPlatformProviderInterface.php');
    self::assertStringContainsString('function cspDirectives(): array', $src);
}
}
