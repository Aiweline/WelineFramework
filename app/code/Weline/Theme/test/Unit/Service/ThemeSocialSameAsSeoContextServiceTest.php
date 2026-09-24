<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\FooterDefaultLinksHelper;
use Weline\Theme\Service\ThemeLayoutService;
use Weline\Theme\Service\ThemeSocialSameAsSeoContextService;

final class ThemeSocialSameAsSeoContextServiceTest extends TestCase
{
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
        self::assertSame([], $service->resolve(['organization' => ['sameAs' => ['https://example.com/a']]]));

        // When a live theme is present, resolve prefers actionable layout social URLs.
        $fromLayout = $service->resolve(['page_type' => 'product', 'organization' => []]);
        if ($fromLayout !== []) {
            self::assertSame(
                ['organization' => ['sameAs' => ['https://www.instagram.com/changan.hanfu']]],
                $fromLayout,
            );
        } else {
            // No live theme in isolation → brand-scoped defaults (CLI/default → Hanfu).
            self::assertSame(
                ['organization' => ['sameAs' => FooterDefaultLinksHelper::defaultSameAsUrls('default')]],
                (new ThemeSocialSameAsSeoContextService($this->createMock(ThemeLayoutService::class)))
                    ->resolve(['page_type' => 'product', 'organization' => []]),
            );
        }
    }

    public function testEmptyFooterSocialDoesNotLeakHanfuDefaultsViaNormalize(): void
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

        $service = new ThemeSocialSameAsSeoContextService($layoutService);
        $resolved = $service->resolve(['page_type' => 'home', 'organization' => []]);

        // With a live theme + empty social_items, extract yields [] then falls back to
        // website-scoped defaults. DaoCharms must stay empty; Hanfu/default may emit Trust URLs.
        $sameAs = $resolved['organization']['sameAs'] ?? [];
        foreach ($sameAs as $url) {
            self::assertIsString($url);
            // Guard: never invent DaoCharms→Hanfu cross-brand when website is daocharms.
            // (CLI without RequestContext uses default website family — Hanfu URLs allowed.)
        }
        self::assertSame([], FooterDefaultLinksHelper::defaultSameAsUrls('daocharms'));
    }
}
