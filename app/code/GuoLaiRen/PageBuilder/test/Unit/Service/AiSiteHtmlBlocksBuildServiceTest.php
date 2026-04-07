<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\AiSiteHtmlBlocksBuildService;
use GuoLaiRen\PageBuilder\Service\AiSitePageBlueprintService;
use PHPUnit\Framework\TestCase;

class AiSiteHtmlBlocksBuildServiceTest extends TestCase
{
    public function testBuildPlaceholderBlocksUsesStructuredHeaderFooterAndGroupedFooterLinks(): void
    {
        $service = new AiSiteHtmlBlocksBuildService(new AiSitePageBlueprintService());

        $websiteProfile = [
            'site_title' => 'West Sydney Support Hub',
            'site_tagline' => 'Local support and clear policy information',
            'brief_description' => 'A West Sydney customer website with about, policy information, blog, and contact guidance.',
            'target_domain' => 'westsydney.example.test',
        ];
        $scope = [
            'site_title' => 'West Sydney Support Hub',
            'brief_description' => 'A West Sydney customer website with about, policy information, blog, and contact guidance.',
            'page_types' => [
                Page::TYPE_HOME,
                Page::TYPE_ABOUT,
                Page::TYPE_PRIVACY_POLICY,
                Page::TYPE_TERMS_OF_SERVICE,
                Page::TYPE_BLOG_LIST,
                Page::TYPE_CONTACT,
            ],
        ];

        $blocks = $service->buildPlaceholderBlocksForPageType(Page::TYPE_HOME, $websiteProfile, $scope);

        self::assertGreaterThanOrEqual(3, \count($blocks));
        self::assertSame('site_header', (string)($blocks[0]['type'] ?? ''));
        self::assertSame('site_footer', (string)($blocks[\count($blocks) - 1]['type'] ?? ''));

        $headerNav = $blocks[0]['config']['nav_items'] ?? [];
        self::assertIsArray($headerNav);
        self::assertLessThanOrEqual(6, \count($headerNav));
        $headerHrefs = \array_column($headerNav, 'href');
        self::assertTrue(
            \in_array('/privacy', $headerHrefs, true)
            || \in_array('/terms', $headerHrefs, true)
            || \in_array('/refund', $headerHrefs, true)
            || \in_array('/shipping', $headerHrefs, true)
            || \in_array('/cookies', $headerHrefs, true),
            'Header nav should surface at least one policy-related page.'
        );

        $footerBlock = $blocks[\count($blocks) - 1];
        $footerConfig = \is_array($footerBlock['config'] ?? null) ? $footerBlock['config'] : [];
        self::assertSame('Featured Pages', (string)($footerConfig['links.column1_title'] ?? ''));
        self::assertSame('Policy Info', (string)($footerConfig['links.column2_title'] ?? ''));
        self::assertSame('All Pages', (string)($footerConfig['links.column3_title'] ?? ''));
        self::assertNotEmpty($footerConfig['links.column1_items'] ?? []);
        self::assertNotEmpty($footerConfig['links.column2_items'] ?? []);
        self::assertGreaterThanOrEqual(5, \count($footerConfig['links.column3_items'] ?? []));
        self::assertStringContainsString('All Pages', (string)($footerBlock['html'] ?? ''));
    }
}
