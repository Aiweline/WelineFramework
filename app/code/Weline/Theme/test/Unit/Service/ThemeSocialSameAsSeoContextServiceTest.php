<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\FooterDefaultLinksHelper;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeLayoutService;
use Weline\Theme\Service\ThemeSocialSameAsSeoContextService;

final class ThemeSocialSameAsSeoContextServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        ThemeData::resetRequestState();
        parent::tearDown();
    }

    public function testExtractsHttpSocialUrlsFromFooterContainerWithoutInventingDefaults(): void
    {
        self::assertSame(
            [[
                'name' => 'Instagram',
                'icon' => 'fab fa-instagram',
                'url' => 'https://www.instagram.com/changan.hanfu',
            ]],
            FooterDefaultLinksHelper::normalizeSocialItems([
                ['name' => 'Instagram', 'icon' => 'fab fa-instagram', 'url' => 'https://www.instagram.com/changan.hanfu'],
                ['name' => 'Dead', 'icon' => 'fab fa-x', 'url' => '#'],
            ])
        );

        // SEO extract must not substitute brand defaults for intentional empty social_items.
        self::assertSame([], FooterDefaultLinksHelper::actionableSocialItems([]));
        self::assertSame([], FooterDefaultLinksHelper::actionableSocialItems([
            ['name' => 'Dead', 'icon' => 'fab fa-x', 'url' => '#'],
        ]));

        $layoutService = $this->createMock(ThemeLayoutService::class);
        $layoutService->method('getPublishedLayout')->willReturn([
            'footer' => [
                'widgets' => [[
                    'widget_code' => 'footer-container',
                    'widget_type' => 'footer-container',
                    'config' => [
                        'social_items' => [
                            ['name' => 'Instagram', 'icon' => 'fab fa-instagram', 'url' => 'https://www.instagram.com/changan.hanfu'],
                            ['name' => 'Dead', 'icon' => 'fab fa-x', 'url' => '#'],
                        ],
                    ],
                ]],
            ],
        ]);

        $service = new ThemeSocialSameAsSeoContextService($layoutService);

        // Existing sameAs facts win; the integration never overwrites them.
        self::assertSame([], $service->resolve(['organization' => ['sameAs' => ['https://example.com/a']]]));

        // A live theme prefers actionable layout social URLs and drops `#` placeholders.
        $this->bindCurrentTheme();
        self::assertSame(
            ['organization' => ['sameAs' => ['https://www.instagram.com/changan.hanfu']]],
            $service->resolve(['page_type' => 'product', 'organization' => []]),
        );
    }

    public function testEmptyFooterSocialFallsBackToThemeDefaultsWithoutInventingUrls(): void
    {
        $layoutService = $this->createMock(ThemeLayoutService::class);
        $layoutService->method('getPublishedLayout')->willReturn([
            'footer' => [
                'widgets' => [[
                    'widget_code' => 'footer-container',
                    'widget_type' => 'footer-container',
                    'config' => [
                        'show_social' => false,
                        'social_items' => [],
                    ],
                ]],
            ],
        ]);

        $this->bindCurrentTheme();
        $service = new ThemeSocialSameAsSeoContextService($layoutService);
        $resolved = $service->resolve(['page_type' => 'home', 'organization' => []]);

        // With empty layout social_items the theme data pack is the only source of truth.
        $defaults = FooterDefaultLinksHelper::defaultSameAsUrls();
        if ($defaults === []) {
            // A theme without brand profiles must never invent sameAs URLs.
            self::assertSame([], $resolved);

            return;
        }

        self::assertSame(['organization' => ['sameAs' => $defaults]], $resolved);
        foreach ($defaults as $url) {
            self::assertMatchesRegularExpression('#^https?://#i', $url);
        }
    }

    private function bindCurrentTheme(): void
    {
        $theme = $this->createMock(WelineTheme::class);
        $theme->method('getId')->willReturn(3);
        ThemeData::setCurrentTheme($theme);
    }
}
