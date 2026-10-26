<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Websites\Api\Crawler\CrawlerPolicyEntry;
use Weline\Websites\Api\Crawler\CrawlerPolicyProviderInterface;
use Weline\Websites\Observer\CrawlerPolicyRobotsContribute;

final class CrawlerPolicyRobotsContributeTest extends TestCase
{
    public function testBlockedBotGetsDisallowAndHardFloorBotIsSkipped(): void
    {
        $provider = new class implements CrawlerPolicyProviderInterface {
            public function isEnabled(int $websiteId): bool
            {
                return true;
            }

            public function entriesForWebsite(int $websiteId): array
            {
                return [
                    new CrawlerPolicyEntry('diffbot', 'Diffbot', 'block', true, false, 'scraper', '', '/Diffbot/i', ['Diffbot'], 'Diffbot'),
                    new CrawlerPolicyEntry('amazon', 'Amazonbot', 'block', true, false, 'ai', '', '/Amazonbot/i', ['Amazonbot'], 'Amazonbot'),
                ];
            }

            public function revisionForWebsite(int $websiteId): string
            {
                return 'test';
            }
        };

        $bag = new DataObject([
            'website_id' => 1,
            'robots_enabled' => true,
            'global_lines' => ['License: https://example.test/license.xml'],
        ]);
        $event = new Event(['data' => $bag]);

        (new CrawlerPolicyRobotsContribute($provider))->execute($event);

        $lines = (array)$bag->getData('global_lines');
        self::assertContains('User-agent: Diffbot', $lines);
        self::assertContains('Disallow: /', $lines);
        self::assertNotContains('User-agent: Amazonbot', $lines, 'hard-floor allowlist must not be blocked in robots.txt');
    }

    public function testSameTokenAllowWinsAndGoogleExtendedLegacyIsSuppressed(): void
    {
        $provider = new class implements CrawlerPolicyProviderInterface {
            public function isEnabled(int $websiteId): bool
            {
                return true;
            }

            public function entriesForWebsite(int $websiteId): array
            {
                return [
                    new CrawlerPolicyEntry('allow-diffbot', 'Diffbot', 'allow', true, false, 'custom', '', '/Diffbot/i', ['Diffbot'], 'Diffbot'),
                    new CrawlerPolicyEntry('block-diffbot', 'Diffbot', 'block', true, false, 'custom', '', '/Diffbot/i', ['Diffbot'], 'Diffbot'),
                    new CrawlerPolicyEntry('allow-google-ai', 'Google AI', 'allow', true, false, 'ai', '', '/Google-Extended/i', ['Google-Extended'], 'Google-Extended'),
                ];
            }

            public function revisionForWebsite(int $websiteId): string
            {
                return 'test';
            }
        };

        $bag = new DataObject([
            'website_id' => 1,
            'robots_enabled' => true,
            'global_lines' => [],
        ]);

        (new CrawlerPolicyRobotsContribute($provider))->execute(new Event(['data' => $bag]));

        self::assertNotContains('User-agent: Diffbot', (array)$bag->getData('global_lines'));
        self::assertTrue((bool)$bag->getData('suppress_google_extended'));
    }
}
