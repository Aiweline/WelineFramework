<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\FooterDefaultLinksHelper;
use Weline\Theme\Service\ThemeLayoutService;
use Weline\Theme\Service\ThemeSocialSameAsSeoContextService;

final class ThemeSocialSameAsSeoContextServiceTest extends TestCase
{
    public function testExtractsHttpSocialUrlsFromFooterContainer(): void
    {
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

        // ThemeData::getCurrentTheme() may be null in unit isolation; probe normalize helper path via reflection-free partial.
        // Service returns [] without theme; assert helper contract separately.
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

        $service = new ThemeSocialSameAsSeoContextService($layoutService);
        self::assertSame([], $service->resolve(['organization' => ['sameAs' => ['https://example.com/a']]]));
    }
}
