<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Security\CrawlerBlockCatalog;
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
        $hit = CrawlerBlockCatalog::match(
            'Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)',
            $rule
        );
        self::assertNotNull($hit);
        self::assertSame('gptbot', $hit['id']);
    }

    public function testDefaultWebsiteRuleBlocksParasitesAndAllowsSearch(): void
    {
        $rule = CrawlerBlockCatalog::defaultWebsiteRule();
        self::assertNotEmpty($rule['entries']);
        self::assertNull(CrawlerBlockCatalog::match(
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            $rule
        ));
        self::assertNotNull(CrawlerBlockCatalog::match(
            'Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)',
            $rule
        ));
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
