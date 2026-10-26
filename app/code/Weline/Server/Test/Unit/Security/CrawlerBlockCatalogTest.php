<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Security;

use PHPUnit\Framework\TestCase;
use Weline\Server\Security\CrawlerBlockCatalog;

final class CrawlerBlockCatalogTest extends TestCase
{
    public function testDefaultBuiltinsBlockParasiticCrawlers(): void
    {
        $rule = CrawlerBlockCatalog::defaultRule();

        // GPTBot/OAI-SearchBot 自 b3b6a5ce76 起属 allowlist（AI 放行），不再被 block。
        self::assertNull(CrawlerBlockCatalog::match(
            'Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)',
            $rule
        ));
        self::assertTrue(CrawlerBlockCatalog::isAllowPass(
            'Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)',
            $rule
        ));

        // Bytespider 亦在 allowlist（ai 类放行）。
        self::assertNull(CrawlerBlockCatalog::match(
            'Mozilla/5.0 (compatible; Bytespider; https://zhanzhang.toutiao.com/)',
            $rule
        ));

        $hit = CrawlerBlockCatalog::match('python-requests/2.31.0', $rule);
        self::assertNotNull($hit);
        self::assertSame('python_requests', $hit['id']);

        $hit = CrawlerBlockCatalog::match(
            'Mozilla/5.0 (compatible; SemrushBot/7~bl; +http://www.semrush.com/bot.html)',
            $rule
        );
        self::assertNotNull($hit);
        self::assertSame('semrush', $hit['id']);
    }

    public function testFriendlySearchEnginesRemainAllowed(): void
    {
        $rule = CrawlerBlockCatalog::defaultRule();

        self::assertNull(CrawlerBlockCatalog::match(
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            $rule
        ));
        self::assertNull(CrawlerBlockCatalog::match(
            'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
            $rule
        ));
        self::assertNull(CrawlerBlockCatalog::match(
            'Mozilla/5.0 (compatible; Baiduspider/2.0; +http://www.baidu.com/search/spider.html)',
            $rule
        ));
    }

    public function testGscInspectionAndGoogleOtherRemainAllowed(): void
    {
        $rule = CrawlerBlockCatalog::defaultRule();

        self::assertNull(CrawlerBlockCatalog::match(
            'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); Google-InspectionTool/1.0',
            $rule
        ));
        self::assertNull(CrawlerBlockCatalog::match(
            'Mozilla/5.0 (compatible; GoogleOther/1.0; +https://developers.google.com/search/docs/crawling-indexers/overview-crawler)',
            $rule
        ));
        self::assertTrue(CrawlerBlockCatalog::isAllowPass(
            'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); Google-InspectionTool/1.0',
            $rule
        ));
    }

    public function testChineseSearchBotsRemainAllowed(): void
    {
        $rule = CrawlerBlockCatalog::defaultRule();

        $friendlyUas = [
            'Mozilla/5.0 (compatible; Baiduspider-render/2.0; +http://www.baidu.com/search/spider.html)',
            'Sogou web spider/4.0(+http://www.sogou.com/docs/help/webmasters.htm)',
            'Mozilla/5.0 (compatible; HaoSouSpider/1.0; +http://www.so.com/help/help_3_2.html)',
            'Mozilla/5.0 (compatible; YisouSpider/5.0; +http://www.yisou.com/help.html)',
            'Mozilla/5.0 (compatible; ToutiaoSpider/1.0; +http://zhanzhang.toutiao.com/)',
            'Mozilla/5.0 (compatible; bytedance_bot/1.0)',
            'Mozilla/5.0 (compatible; DouyinBot/1.0)',
            'msnbot-media/1.1 (+http://search.msn.com/msnbot.htm)',
        ];
        foreach ($friendlyUas as $ua) {
            self::assertNull(CrawlerBlockCatalog::match($ua, $rule), $ua);
            self::assertTrue(CrawlerBlockCatalog::isAllowPass($ua, $rule), $ua);
        }
    }

    public function testStorefrontWarmupInternalUaRemainsAllowed(): void
    {
        $rule = CrawlerBlockCatalog::defaultRule();

        self::assertNull(CrawlerBlockCatalog::match('WelineStorefrontWarmup/1.0', $rule));
        self::assertTrue(CrawlerBlockCatalog::isAllowPass('WelineStorefrontWarmup/1.0', $rule));
    }

    public function testDisabledBuiltinIdIsSkipped(): void
    {
        $rule = CrawlerBlockCatalog::defaultRule();
        $rule['disabled_builtin_ids'] = ['semrush'];

        // semrush 被禁用后不再命中 block。
        self::assertNull(CrawlerBlockCatalog::match(
            'Mozilla/5.0 (compatible; SemrushBot/7~bl; +http://www.semrush.com/bot.html)',
            $rule
        ));
        // ClaudeBot 属 allowlist（AI 放行），无论 disable 与否都不会 block。
        self::assertNull(CrawlerBlockCatalog::match(
            'Mozilla/5.0 (compatible; ClaudeBot/1.0)',
            $rule
        ));
        // 其他寄生爬虫仍正常拦截。
        self::assertNotNull(CrawlerBlockCatalog::match(
            'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)',
            $rule
        ));
    }

    public function testCustomEntriesMatchWhenEnabled(): void
    {
        $rule = CrawlerBlockCatalog::defaultRule();
        $rule['custom_entries'] = [
            [
                'id' => 'evil-scraper',
                'name' => 'EvilScraper',
                'pattern' => '/EvilScraper\\/1\\.0/i',
                'enabled' => true,
                'reason' => 'Custom parasite',
            ],
            [
                'id' => 'disabled-one',
                'name' => 'Disabled',
                'pattern' => '/ShouldNotHit/i',
                'enabled' => false,
            ],
        ];

        $hit = CrawlerBlockCatalog::match('EvilScraper/1.0 (+https://example.test)', $rule);
        self::assertNotNull($hit);
        self::assertSame('evil-scraper', $hit['id']);
        self::assertNull(CrawlerBlockCatalog::match('ShouldNotHit/9', $rule));
    }

    public function testAllowlistWinsEvenIfCustomPatternWouldMatch(): void
    {
        $rule = CrawlerBlockCatalog::defaultRule();
        $rule['custom_entries'] = [
            [
                'id' => 'catch-google',
                'name' => 'CatchGoogle',
                'pattern' => '/Google/i',
                'enabled' => true,
            ],
        ];

        self::assertNull(CrawlerBlockCatalog::match(
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            $rule
        ));
    }

    public function testExplicitEntriesAllowActionBeatsBlock(): void
    {
        $rule = [
            'enabled' => true,
            'entries' => [
                [
                    'id' => 'allow-gpt',
                    'name' => 'Allow GPT',
                    'pattern' => '/GPTBot/i',
                    'action' => 'allow',
                    'enabled' => true,
                ],
                [
                    'id' => 'block-gpt',
                    'name' => 'Block GPT',
                    'pattern' => '/GPTBot/i',
                    'action' => 'block',
                    'enabled' => true,
                ],
            ],
        ];

        self::assertNull(CrawlerBlockCatalog::match(
            'Mozilla/5.0 (compatible; GPTBot/1.0)',
            $rule
        ));
        self::assertTrue(CrawlerBlockCatalog::isAllowPass(
            'Mozilla/5.0 (compatible; GPTBot/1.0)',
            $rule
        ));
    }

    public function testIsAllowPassHardFloorForFriendlySearch(): void
    {
        $google = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
        self::assertTrue(CrawlerBlockCatalog::isAllowPass($google, []));
        self::assertTrue(CrawlerBlockCatalog::isAllowPass($google, CrawlerBlockCatalog::defaultRule()));
        // Even if a site entry tries to block Google, hard floor still passes for ban gate.
        self::assertTrue(CrawlerBlockCatalog::isAllowPass($google, [
            'enabled' => true,
            'entries' => [
                [
                    'id' => 'block-google',
                    'name' => 'Block Google',
                    'pattern' => '/Googlebot/i',
                    'action' => 'block',
                    'enabled' => true,
                ],
            ],
        ]));
        self::assertFalse(CrawlerBlockCatalog::isAllowPass('curl/8.0', []));
        self::assertFalse(CrawlerBlockCatalog::isAllowPass('', CrawlerBlockCatalog::defaultRule()));
    }
}
