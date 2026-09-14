<?php

declare(strict_types=1);

namespace Weline\Social\Test\Unit\Security;

use PHPUnit\Framework\TestCase;
use Weline\Social\Extends\Module\Weline_Framework\Security\Csp\SocialPlatformsCsp;
use Weline\Social\Platform\Messaging\WechatProvider;
use Weline\Social\Platform\Social\TiktokProvider;
use Weline\Social\Platform\Social\XProvider;
use Weline\Social\Platform\Video\YoutubeProvider;

final class SocialPlatformCspDirectivesContractTest extends TestCase
{
    public function testBuiltInPlatformsDeclareCspDirectives(): void
    {
        self::assertContains('https://open.weixin.qq.com', (new WechatProvider())->cspDirectives()['script-src'] ?? []);
        self::assertContains('https://platform.twitter.com', (new XProvider())->cspDirectives()['script-src'] ?? []);
        self::assertContains('https://www.tiktok.com', (new TiktokProvider())->cspDirectives()['frame-src'] ?? []);
        self::assertContains('https://www.youtube.com', (new YoutubeProvider())->cspDirectives()['frame-src'] ?? []);
    }

    public function testSocialPlatformsCspAggregatesProviders(): void
    {
        $contribution = (new SocialPlatformsCsp(static fn (): array => [
            new WechatProvider(),
            new XProvider(),
            new TiktokProvider(),
            new YoutubeProvider(),
        ]))->contribution();

        self::assertContains('https://open.weixin.qq.com', $contribution->directives['script-src'] ?? []);
        self::assertContains('https://x.com', $contribution->directives['frame-src'] ?? []);
        self::assertContains('https://api.tiktok.com', $contribution->directives['connect-src'] ?? []);
        self::assertContains('https://www.youtube-nocookie.com', $contribution->directives['frame-src'] ?? []);
    }

    public function testInterfaceDeclaresCspDirectives(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Interface/SocialPlatformProviderInterface.php'
        );
        self::assertStringContainsString('function cspDirectives(): array', $src);
    }
}
