<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Service\CrawlerPolicyRulePlanner;
use Weline\Websites\Api\Crawler\CrawlerPolicyEntry;

final class CrawlerPolicyRulePlannerTest extends TestCase
{
    public function testBlockTokensAreGroupedIntoOneRule(): void
    {
        $planner = new CrawlerPolicyRulePlanner();
        $planned = $planner->plan([
            $this->entry('diffbot', ['Diffbot'], 'block'),
            $this->entry('scrapy', ['Scrapy'], 'block'),
        ]);

        self::assertCount(1, $planned['rules']);
        self::assertStringStartsWith('weline-crawler:0:v1:block:', $planned['rules'][0]['description']);
        self::assertStringContainsString('contains "diffbot"', $planned['rules'][0]['expression']);
        self::assertStringContainsString(' or ', $planned['rules'][0]['expression']);
        self::assertStringContainsString('contains "scrapy"', $planned['rules'][0]['expression']);
    }

    public function testDescriptionCarriesWebsiteIdForSharedZoneIsolation(): void
    {
        $planner = new CrawlerPolicyRulePlanner();
        $planned = $planner->plan([
            $this->entry('diffbot', ['Diffbot'], 'block'),
        ], 12);

        self::assertStringStartsWith('weline-crawler:12:v1:block:', $planned['rules'][0]['description']);
    }

    public function testAllowTokenWinsOverSameBlockToken(): void
    {
        $planner = new CrawlerPolicyRulePlanner();
        $planned = $planner->plan([
            $this->entry('allow-gpt', ['GPTBot'], 'allow'),
            $this->entry('block-gpt', ['GPTBot'], 'block'),
        ]);

        self::assertSame([], $planned['rules']);
    }

    public function testPrefixCollisionGetsNegation(): void
    {
        $planner = new CrawlerPolicyRulePlanner();
        $planned = $planner->plan([
            $this->entry('allow-extended', ['MyCrawler-Extended'], 'allow'),
            $this->entry('block-base', ['MyCrawler'], 'block'),
        ]);

        self::assertCount(1, $planned['rules']);
        self::assertStringContainsString('contains "mycrawler"', $planned['rules'][0]['expression']);
        self::assertStringContainsString('not lower(http.user_agent) contains "mycrawler-extended"', $planned['rules'][0]['expression']);
    }

    public function testGlobalHardFloorAllowlistWinsOverStaleBlockRows(): void
    {
        $planner = new CrawlerPolicyRulePlanner();
        $planned = $planner->plan([
            $this->entry('stale-amazon', ['Amazonbot'], 'block'),
            $this->entry('stale-cc', ['CCBot'], 'block'),
            $this->entry('diffbot', ['Diffbot'], 'block'),
        ]);

        self::assertCount(1, $planned['rules']);
        self::assertStringContainsString('diffbot', $planned['rules'][0]['expression']);
        self::assertStringNotContainsString('amazonbot', $planned['rules'][0]['expression']);
        self::assertStringNotContainsString('ccbot', $planned['rules'][0]['expression']);
    }

    public function testDisabledAndEmptyTokenEntriesAreSkipped(): void
    {
        $planner = new CrawlerPolicyRulePlanner();
        $planned = $planner->plan([
            $this->entry('empty', ['', 'bad"token'], 'block'),
            $this->entry('disabled', ['Diffbot'], 'block', false),
        ]);

        self::assertSame([], $planned['rules']);
        self::assertContains('empty', $planned['skipped_entries']);
    }

    /**
     * @param list<string> $tokens
     */
    private function entry(string $id, array $tokens, string $action, bool $enabled = true): CrawlerPolicyEntry
    {
        return new CrawlerPolicyEntry(
            $id,
            $id,
            $action,
            $enabled,
            false,
            'custom',
            '',
            '/.*/i',
            $tokens,
            $tokens[0] ?? '',
        );
    }
}
