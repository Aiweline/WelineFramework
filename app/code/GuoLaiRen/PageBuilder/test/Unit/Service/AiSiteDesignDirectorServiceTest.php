<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteBlockMorphologyRegistry;
use GuoLaiRen\PageBuilder\Service\AiSiteDesignDirectorService;
use PHPUnit\Framework\TestCase;

final class AiSiteDesignDirectorServiceTest extends TestCase
{
    public function testMaterializeProducesNonPlaceholderDesignSystem(): void
    {
        $system = (new AiSiteDesignDirectorService())->materialize(
            [
                'site_title' => 'Northstar Clinics',
                'brief_description' => 'Create a precise healthcare website for families comparing trusted local clinics.',
                'page_types' => ['home_page', 'services_page', 'contact_page'],
            ],
            [
                'audience' => 'families and care coordinators',
                'primary_conversion' => 'book a consultation',
            ],
            [
                'style_signature' => 'calm clinical editorial design with strong proof bands',
                'color_scheme' => [
                    'primary' => '#126E82',
                    'surface' => '#F6FAFB',
                    'text' => '#132024',
                    'accent' => '#E6A23C',
                ],
                'typography_spacing_radius' => [
                    'font_family' => 'Inter, system-ui, sans-serif',
                    'heading_voice' => 'confident clinical clarity',
                ],
            ],
            [
                'home_page' => [
                    'blocks' => [
                        ['page_flow_role' => 'opening'],
                        ['page_flow_role' => 'proof'],
                        ['page_flow_role' => 'details'],
                    ],
                ],
            ],
            [
                'summary' => 'clean editorial photography and warm clinic lighting',
            ]
        );

        self::assertSame(1, $system['version'] ?? null);
        self::assertSame('Northstar Clinics', $system['brand_positioning']['site_name'] ?? null);
        self::assertSame('premium', $system['brand_positioning']['market_level'] ?? null);
        self::assertNotEmpty($system['brand_positioning']['trust_angle'] ?? null);
        self::assertNotEmpty($system['tokens']['color_roles']['background'] ?? null);
        self::assertNotEmpty($system['media_strategy']['non_hero_image_rule'] ?? null);
        self::assertNotEmpty($system['media_strategy']['image_treatment'] ?? null);
        self::assertNotEmpty($system['tokens']['container'] ?? null);
        self::assertNotEmpty($system['tokens']['radius_scale'] ?? null);
        self::assertGreaterThanOrEqual(6, \count($system['morphology_pool'] ?? []));
        self::assertStringNotContainsString('clamp(', \json_encode($system['tokens']['type_scale'] ?? [], \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('vw', \json_encode($system['tokens']['type_scale'] ?? [], \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('clamp(', \json_encode($system['tokens']['typography'] ?? [], \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('vw', \json_encode($system['tokens']['typography'] ?? [], \JSON_THROW_ON_ERROR));

        $registryIds = \array_keys((new AiSiteBlockMorphologyRegistry())->all());
        self::assertNotSame([], \array_intersect($registryIds, $system['morphology_pool']));

        $validation = (new AiSiteDesignDirectorService())->validate($system);
        self::assertTrue($validation['valid'], \implode(', ', $validation['errors']));
    }

    public function testValidateRejectsPlaceholderValues(): void
    {
        $service = new AiSiteDesignDirectorService();
        $system = $service->materialize(
            [
                'site_title' => 'Focused Ops',
                'brief_description' => 'Build a low imagery operations platform website.',
                'page_types' => ['home_page'],
                'palette' => [
                    'primary' => '#1F4E79',
                    'surface' => '#FFFFFF',
                    'text' => '#101820',
                    'accent' => '#D9912B',
                ],
            ]
        );
        $system['brand_positioning']['audience'] = 'placeholder';

        $validation = $service->validate($system);

        self::assertFalse($validation['valid']);
        self::assertContains('placeholder_value:brand_positioning.audience', $validation['errors']);
    }

    public function testMinimalImageryBriefLowersMediaDensity(): void
    {
        $system = (new AiSiteDesignDirectorService())->materialize(
            [
                'site_title' => 'OpsDesk',
                'brief_description' => 'Create a text only website with minimal imagery for an operations dashboard.',
                'page_types' => ['home_page'],
                'palette' => [
                    'primary' => '#334155',
                    'surface' => '#F8FAFC',
                    'text' => '#0F172A',
                    'accent' => '#0EA5E9',
                ],
            ]
        );

        self::assertSame('minimal', $system['media_strategy']['density'] ?? null);
        self::assertSame('low', $system['media_strategy']['page_asset_density'] ?? null);
        self::assertSame('airy', $system['style_axis']['density'] ?? null);
        self::assertStringContainsString('CSS motif', (string)($system['media_strategy']['hero_image_rule'] ?? ''));
    }

    public function testNeonCardBriefMaterializesGamingDesignDefaults(): void
    {
        $system = (new AiSiteDesignDirectorService())->materialize(
            [
                'site_title' => '霓虹棋牌馆',
                'brief_description' => '打造一个霓虹棋牌风格的线上娱乐网站，包含玩家证明、玩法亮点、攻略内容和客服支持。',
                'page_types' => ['home_page', 'about_page', 'contact_page', 'blog_list'],
            ],
            [],
            [],
            [
                'home_page' => [
                    'blocks' => [
                        ['page_flow_role' => 'opening'],
                        ['page_flow_role' => 'proof'],
                        ['page_flow_role' => 'details'],
                    ],
                ],
            ]
        );

        self::assertSame('#070A18', $system['tokens']['color_roles']['background'] ?? null);
        self::assertSame('#00F5FF', $system['tokens']['color_roles']['accent'] ?? null);
        self::assertSame('high', $system['media_strategy']['page_asset_density'] ?? null);
        self::assertSame('immersive', $system['style_axis']['density'] ?? null);
        self::assertSame('neon gaming', $system['style_axis']['mood'] ?? null);
        self::assertStringContainsString('Chakra Petch', (string)($system['tokens']['typography']['font_family'] ?? ''));
        self::assertStringContainsString('neon card-room', (string)($system['media_strategy']['hero_image_rule'] ?? ''));
        self::assertStringContainsString('block-specific real images', (string)($system['media_strategy']['non_hero_image_rule'] ?? ''));
    }
}
