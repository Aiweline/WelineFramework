<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Security\CrawlerBlockCatalog;
use Weline\Websites\Service\CrawlerPolicyProvider;
use Weline\Websites\Service\WebsiteCrawlerPolicyService;

final class WebsiteCrawlerPolicyServiceTest extends TestCase
{
    public function testNormalizeKeepsAllowAndBlockActions(): void
    {
        $service = $this->newServiceWithoutDeps();
        $normalized = $service->normalizeInput([
            'enabled' => '1',
            'block_duration' => '3600',
            'entries' => [
                [
                    'id' => 'googlebot',
                    'name' => 'Googlebot',
                    'pattern' => '/Googlebot/i',
                    'action' => 'allow',
                    'enabled' => '1',
                    'builtin' => '1',
                ],
                [
                    'id' => 'gptbot',
                    'name' => 'GPTBot',
                    'pattern' => '/GPTBot/i',
                    'action' => 'block',
                    'enabled' => '1',
                ],
                [
                    'id' => 'blank',
                    'name' => '',
                    'pattern' => '',
                    'action' => 'block',
                ],
            ],
        ]);

        self::assertTrue($normalized['enabled']);
        self::assertSame(0, $normalized['block_duration']);
        self::assertTrue($normalized['ai_default_open']);
        self::assertFalse($service->normalizeInput(['ai_default_open' => '0'])['ai_default_open']);
        self::assertCount(2, $normalized['entries']);
        self::assertSame('allow', $normalized['entries'][0]['action']);
        self::assertSame('block', $normalized['entries'][1]['action']);
    }

    public function testWebsiteEntriesAllowWinsBeforeBlock(): void
    {
        $rule = [
            'enabled' => true,
            'block_duration' => 86400,
            'entries' => [
                [
                    'id' => 'allow-google',
                    'name' => 'Allow Google',
                    'pattern' => '/Google/i',
                    'action' => 'allow',
                    'enabled' => true,
                ],
                [
                    'id' => 'block-google',
                    'name' => 'Block Google',
                    'pattern' => '/Googlebot/i',
                    'action' => 'block',
                    'enabled' => true,
                ],
                [
                    'id' => 'gptbot',
                    'name' => 'GPTBot',
                    'pattern' => '/GPTBot/i',
                    'action' => 'block',
                    'enabled' => true,
                ],
            ],
        ];

        self::assertNull(CrawlerBlockCatalog::match(
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            $rule
        ));
        // GPTBot 在硬底 allowlist（openai_crawlers），站级 block 条目不改变放行语义。
        self::assertNull(CrawlerBlockCatalog::match(
            'Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)',
            $rule
        ));
        self::assertTrue(CrawlerBlockCatalog::isAllowPass(
            'Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)',
            $rule
        ));
        // 非 allowlist 爬虫仍按条目拦截。
        $hit = CrawlerBlockCatalog::match(
            'Mozilla/5.0 (compatible; SomeParasiteBot/1.0)',
            ['enabled' => true, 'block_duration' => 86400, 'entries' => [
                ['id' => 'parasite', 'name' => 'Parasite', 'pattern' => '/SomeParasiteBot/i', 'action' => 'block', 'enabled' => true],
            ]]
        );
        self::assertNotNull($hit);
        self::assertSame('parasite', $hit['id']);
    }

    public function testDefaultWebsiteRuleBlocksParasitesAndAllowsSearch(): void
    {
        $rule = CrawlerBlockCatalog::defaultWebsiteRule();
        self::assertNotEmpty($rule['entries']);
        self::assertNull(CrawlerBlockCatalog::match(
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            $rule
        ));
        // GPTBot 属 allowlist（AI 放行）——默认站级规则放行而非拦截。
        self::assertNull(CrawlerBlockCatalog::match(
            'Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)',
            $rule
        ));
        self::assertTrue(CrawlerBlockCatalog::isAllowPass(
            'Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)',
            $rule
        ));
        // 寄生爬虫仍被默认规则拦截。
        self::assertNotNull(CrawlerBlockCatalog::match(
            'Mozilla/5.0 (compatible; SemrushBot/7~bl; +http://www.semrush.com/bot.html)',
            $rule
        ));
    }

    public function testNormalizeAddsUaTokensAndRobotsToken(): void
    {
        $service = $this->newServiceWithoutDeps();
        $normalized = $service->normalizeInput([
            'entries' => [
                [
                    'id' => 'openai_crawlers',
                    'name' => 'OpenAI',
                    'pattern' => '/GPTBot|OAI-SearchBot|ChatGPT-User/i',
                    'action' => 'allow',
                ],
                [
                    'id' => 'custom',
                    'name' => 'Custom',
                    'pattern' => '/CustomBot/i',
                    'action' => 'block',
                    'ua_tokens' => 'CustomBot, "Bad\\Token"',
                    'robots_token' => 'CustomBot',
                ],
            ],
        ]);

        self::assertSame(
            ['GPTBot', 'OAI-SearchBot', 'ChatGPT-User'],
            $normalized['entries'][0]['ua_tokens'],
            'builtin entries must backfill literal UA tokens'
        );
        self::assertSame('GPTBot', $normalized['entries'][0]['robots_token']);
        self::assertSame(['CustomBot'], $normalized['entries'][1]['ua_tokens']);
        self::assertSame('CustomBot', $normalized['entries'][1]['robots_token']);
    }

    public function testProviderEntriesExposeTokensAndRevisionIsOrderIndependent(): void
    {
        $service = $this->getMockBuilder(WebsiteCrawlerPolicyService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['loadRule'])
            ->getMock();
        $ruleA = [
            'enabled' => true,
            'entries' => CrawlerBlockCatalog::defaultWebsiteEntries(),
        ];
        $ruleB = $ruleA;
        $ruleB['entries'] = \array_reverse($ruleB['entries']);
        $service->method('loadRule')->willReturnOnConsecutiveCalls($ruleA, $ruleB, $ruleA);

        $provider = new CrawlerPolicyProvider($service);
        $entries = $provider->entriesForWebsite(1);
        self::assertNotEmpty($entries);
        self::assertSame('Googlebot', $entries[0]->uaTokens[0] ?? null);

        $first = $provider->revisionForWebsite(1);
        $second = $provider->revisionForWebsite(1);
        self::assertSame($first, $second, 'revision must not depend on entry order');
    }

    public function testSyncToWlsDomainOverridesReturnContract(): void
    {
        $ref = new \ReflectionMethod(WebsiteCrawlerPolicyService::class, 'syncToWlsDomainOverrides');
        $type = $ref->getReturnType();
        self::assertNotNull($type);
        self::assertSame('array', $type instanceof \ReflectionNamedType ? $type->getName() : (string)$type);
    }

    private function newServiceWithoutDeps(): WebsiteCrawlerPolicyService
    {
        $ref = new \ReflectionClass(WebsiteCrawlerPolicyService::class);

        return $ref->newInstanceWithoutConstructor();
    }
}
