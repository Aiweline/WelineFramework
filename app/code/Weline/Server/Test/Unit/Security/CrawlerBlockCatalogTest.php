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

        $hit = CrawlerBlockCatalog::match(
            'Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)',
            $rule
        );
        self::assertNotNull($hit);
        self::assertSame('gptbot', $hit['id']);

        $hit = CrawlerBlockCatalog::match(
            'Mozilla/5.0 (compatible; Bytespider; https://zhanzhang.toutiao.com/)',
            $rule
        );
        self::assertNotNull($hit);
        self::assertSame('bytespider', $hit['id']);

        $hit = CrawlerBlockCatalog::match('python-requests/2.31.0', $rule);
        self::assertNotNull($hit);
        self::assertSame('python_requests', $hit['id']);
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

    public function testDisabledBuiltinIdIsSkipped(): void
    {
        $rule = CrawlerBlockCatalog::defaultRule();
        $rule['disabled_builtin_ids'] = ['gptbot'];

        self::assertNull(CrawlerBlockCatalog::match(
            'Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)',
            $rule
        ));
        self::assertNotNull(CrawlerBlockCatalog::match(
            'Mozilla/5.0 (compatible; ClaudeBot/1.0)',
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
    }
}
