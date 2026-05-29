<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteAssetManifestService;
use PHPUnit\Framework\TestCase;

/**
 * 强行契约：图像 prompt 的"PRIMARY SUBJECT"必须由用户业务诉求（brief_description）
 * 主导，site_title 仅作为 wordmark 参考；同时单 block 图像必须禁止整页 mockup。
 * 本测试锁定 buildPrompt 在不同 slot 类型下的强契约约束。
 */
final class AiSiteAssetManifestServicePromptTest extends TestCase
{
    public function testNonLogoSlotPromptForbidsFullSiteMockup(): void
    {
        $service = new AiSiteAssetManifestService();

        $slot = [
            'slot_id' => 'page:home_page:content-home-page-hero',
            'slot_type' => 'hero_image',
            'page_type' => 'home_page',
            'block_key' => 'content/home-page-hero',
            'brief' => 'Home Hero visual that illustrates the block promise.',
            'label' => 'Home Hero',
            'field' => 'image',
        ];
        $scope = [
            'website_profile' => ['site_title' => 'Teenipiya'],
        ];

        $prompt = $service->buildPrompt($slot, $scope);

        self::assertStringContainsString('Block-only image artifact contract', $prompt);
        self::assertStringContainsString('not as a rendered website or page screenshot', $prompt);
        self::assertStringContainsString('DO NOT include website chrome', $prompt);
        self::assertStringContainsString('header, navigation, footer', $prompt);
        self::assertStringContainsString('CTA buttons', $prompt);
        self::assertStringContainsString('DO NOT include any readable text', $prompt);
        self::assertStringContainsString('multi-section page previews', $prompt);

        self::assertStringContainsString('Brand context (do not render as text on the image): Teenipiya', $prompt);
        self::assertStringNotContainsString('Website: Teenipiya', $prompt, 'Website: 标签会被解读为画一个网站，必须避免。');
    }

    public function testLogoSlotPromptUsesSubjectFirstContractInsteadOfBrandFirst(): void
    {
        // 强行契约：logo prompt 不能再用"Brand name (for logo only): X"作为主体，
        // 而必须以"PRIMARY SUBJECT"为首要契约——AI 才会把业务主体（如棋牌）画进图，
        // 不会照着站名字面（如 "Teenipiya"）凭空发挥。
        $service = new AiSiteAssetManifestService();

        $slot = [
            'slot_id' => 'identity:website-logo',
            'slot_type' => 'logo_icon',
            'block_key' => 'identity',
            'field' => 'logo',
            'brief' => 'Generate the official website logo for "Teenipiya". Strong constraints: PNG output with transparent alpha background.',
            'label' => 'Website logo',
        ];
        $scope = [
            'website_profile' => [
                'site_title' => 'Teenipiya',
                'brief_description' => 'India-focused online card gaming club with APK downloads and 200% welcome bonus.',
                'site_tagline' => 'Play & Win Big — Royal Card Club India',
            ],
        ];

        $prompt = $service->buildPrompt($slot, $scope);

        // 主体契约必须是第一行，且必须包含业务诉求关键名词
        $firstLine = \explode("\n", $prompt)[0] ?? '';
        self::assertStringStartsWith('PRIMARY SUBJECT', $firstLine);
        self::assertStringContainsString('the logo mark/glyph MUST visually depict this business', $firstLine);
        self::assertStringContainsString('India-focused online card gaming club', $firstLine);

        // brand name 必须降级为 wordmark text，不能作为 primary subject
        self::assertStringContainsString('Optional brand wordmark text', $prompt);
        self::assertStringContainsString('Teenipiya', $prompt);
        self::assertStringNotContainsString('Brand name (for logo only)', $prompt);

        // logo 输出物理约束保留
        self::assertStringContainsString('Logo output requirements', $prompt);
        self::assertStringContainsString('transparent alpha background', $prompt);

        // slot.brief 中 "Generate the official website logo for X" 必须被改写，避免主体冲突
        self::assertStringNotContainsString('Generate the official website logo for "Teenipiya"', $prompt);
        self::assertStringContainsString('Logo specification:', $prompt);

        // 必须有契约复述，避免 prompt 漂移
        self::assertStringContainsString('Reinforced contract', $prompt);
    }

    public function testFaviconSlotPromptUsesFaviconSpecificSubjectContract(): void
    {
        $service = new AiSiteAssetManifestService();

        $slot = [
            'slot_id' => 'identity:site-title-icon',
            'slot_type' => 'logo_icon',
            'kind' => 'site_title_icon',
            'field' => 'icon',
            'brief' => 'Generate the website title icon / favicon for "Teenipiya". Strong constraints: square 1:1 composition.',
            'label' => 'Website Title Icon',
        ];
        $scope = [
            'website_profile' => [
                'site_title' => 'Teenipiya',
                'brief_description' => 'India-focused online card gaming club.',
            ],
        ];

        $prompt = $service->buildPrompt($slot, $scope);

        $firstLine = \explode("\n", $prompt)[0] ?? '';
        self::assertStringStartsWith('PRIMARY SUBJECT', $firstLine);
        self::assertStringContainsString('favicon/title icon glyph MUST visually depict this business', $firstLine);
        self::assertStringContainsString('India-focused online card gaming club', $firstLine);

        // favicon brief 头句也必须被改写
        self::assertStringNotContainsString('Generate the website title icon / favicon for "Teenipiya"', $prompt);
        self::assertStringContainsString('Icon specification:', $prompt);
    }

    public function testHeroSlotPromptLeadsWithBusinessSubjectBeforeBrandContext(): void
    {
        $service = new AiSiteAssetManifestService();

        $slot = [
            'slot_id' => 'page:home_page:content-home-page-hero',
            'slot_type' => 'hero_image',
            'page_type' => 'home_page',
            'brief' => 'Home Hero visual.',
            'label' => 'Home Hero',
            'field' => 'image',
        ];
        $scope = [
            'website_profile' => [
                'site_title' => 'Teenipiya',
                'brief_description' => 'India-focused online card gaming club with APK downloads.',
            ],
        ];

        $prompt = $service->buildPrompt($slot, $scope);

        // 第 1 行必须是 PRIMARY SUBJECT，第 1 行必须出现"India ... card gaming"
        $lines = \explode("\n", $prompt);
        self::assertStringStartsWith('PRIMARY SUBJECT', $lines[0] ?? '');
        self::assertStringContainsString('India-focused online card gaming club', $lines[0] ?? '');

        // brand 仅出现在 PRIMARY SUBJECT 之后，且仅作为 "Brand context"，不作为主体
        $brandPos = \strpos($prompt, 'Brand context (do not render as text on the image): Teenipiya');
        $subjectPos = \strpos($prompt, 'PRIMARY SUBJECT');
        self::assertNotFalse($brandPos);
        self::assertNotFalse($subjectPos);
        self::assertGreaterThan($subjectPos, $brandPos, 'PRIMARY SUBJECT 必须出现在 brand context 之前。');
    }

    public function testSyncFromTaskPlanAddsHeroBannerSlotFromExecutionBlueprintPages(): void
    {
        $service = new AiSiteAssetManifestService();

        $scope = [
            'website_profile' => [
                'brief_description' => 'India card gaming club with APK downloads.',
            ],
            'build_plan_v2' => [
                'blocks' => [
                    [
                        'page_type' => 'home_page',
                        'section_key' => 'home_hero',
                        'page_flow_role' => 'opening',
                        'goal' => 'Play royal card games tonight',
                        'image_intent' => [
                            'needs_image' => true,
                            'image_role' => 'hero_image',
                            'image_subject' => 'card game lobby hero',
                        ],
                    ],
                    [
                        'page_type' => 'home_page',
                        'section_key' => 'features',
                        'page_flow_role' => 'details',
                        'goal' => 'Show card room features',
                        'image_intent' => [
                            'needs_image' => true,
                            'image_role' => 'section_image',
                            'image_subject' => 'card room features',
                        ],
                    ],
                ],
            ],
        ];

        $manifest = $service->syncFromBuildPlan($scope);
        $slots = \is_array($manifest['slots'] ?? null) ? $manifest['slots'] : [];

        self::assertArrayHasKey('page:home_page:content-home-page-home-hero', $slots);
        self::assertArrayHasKey('page:home_page:content-home-page-features', $slots);
        self::assertArrayNotHasKey('home_hero', $slots);
        self::assertArrayNotHasKey('page:home_page:home_hero', $slots);
        self::assertArrayNotHasKey('page:home_page:features', $slots);
        $heroSlot = $slots['page:home_page:content-home-page-home-hero'];
        self::assertSame('hero_image', (string)($heroSlot['slot_type'] ?? ''));
        self::assertStringContainsString('Play royal card games tonight', (string)($heroSlot['label'] ?? ''));

        // banner slot.brief 必须以 PRIMARY SUBJECT 开头，业务诉求必须出现在第 1 行
        $brief = (string)($heroSlot['brief'] ?? '');
        self::assertStringStartsWith('PRIMARY SUBJECT', $brief);
        self::assertStringContainsString('India card gaming club', $brief);
    }

    public function testNeonCardSectionPromptAddsBlockSpecificStyleLock(): void
    {
        $service = new AiSiteAssetManifestService();

        $slot = [
            'slot_id' => 'page:home_page:game-features:details:image',
            'slot_type' => 'section_image',
            'page_type' => 'home_page',
            'block_key' => 'game_features',
            'brief' => 'Block visual for game_features: neon card-game feature scene with poker cards, mahjong tiles, chips, and live table UI.',
            'label' => 'Game features image',
            'field' => 'image',
        ];
        $scope = [
            'website_profile' => [
                'site_title' => '霓虹棋牌馆',
                'brief_description' => '霓虹棋牌风格的线上娱乐网站，包含游戏房间、玩家证明、攻略内容和客服支持。',
                'site_tagline' => '深色霓虹牌桌体验',
            ],
        ];

        $prompt = $service->buildPrompt($slot, $scope);
        $firstLine = \explode("\n", $prompt)[0] ?? '';

        self::assertStringContainsString('game_features', $firstLine);
        self::assertStringContainsString('neon card-game feature scene', $firstLine);
        self::assertStringContainsString('Neon card-game image style lock', $prompt);
        self::assertStringContainsString('Each image MUST express its own block role', $prompt);
        self::assertStringContainsString('poker chips', $prompt);
        self::assertStringContainsString('mahjong tiles', $prompt);
        self::assertStringContainsString('game_features', $prompt);
    }

    public function testSyncFromBuildPlanPreservesVerifiedSlotAcrossSamePlanningContext(): void
    {
        $service = new AiSiteAssetManifestService();
        $slotId = 'page:home_page:hero:opening:image';
        $finalUrl = '/pub/media/page-build/ai-generated/example.test/page-home_page-hero-opening-image.png';

        $scope = [
            'stage1_contract' => [
                'contract_hash' => 'same-stage-contract',
            ],
            'website_profile' => [
                'brief_description' => 'A premium Sichuan restaurant website.',
            ],
            'asset_manifest' => [
                'slots' => [
                    $slotId => [
                        'slot_id' => $slotId,
                        'slot_type' => 'hero_image',
                        'page_type' => 'home_page',
                        'block_key' => 'hero',
                        'section_code' => 'content/home-page-hero',
                        'task_key' => 'page:home_page:content/home-page-hero',
                        'label' => 'Hero visual',
                        'brief' => 'Verified hero visual.',
                        'source' => 'fixture',
                        'status' => 'generated',
                        'final_url' => $finalUrl,
                        'variants' => [[
                            'url' => $finalUrl,
                            'path' => \ltrim($finalUrl, '/'),
                            'mime_type' => 'image/png',
                            'mode' => 'fixture',
                        ]],
                        'planning_context_hash' => 'same-stage-contract',
                    ],
                ],
            ],
            'build_plan_v2' => [
                'blocks' => [
                    [
                        'page_type' => 'home_page',
                        'section_key' => 'hero',
                        'block_id' => 'home_page.hero',
                        'page_flow_role' => 'opening',
                        'image_intent' => [
                            'needs_image' => true,
                            'image_role' => 'hero_image',
                            'image_subject' => 'restaurant hero',
                        ],
                        'asset_requirements' => [
                            [
                                'slot_id' => $slotId,
                                'slot_type' => 'hero_image',
                                'brief' => 'Legacy duplicate requirement for the same slot.',
                            ],
                        ],
                        'block_contract' => [
                            'page_flow_role' => 'opening',
                            'media_strategy' => [
                                'needs_real_image' => true,
                                'asset_slot_id' => $slotId,
                                'image_subject' => 'restaurant hero',
                                'placement' => 'background_layer',
                            ],
                        ],
                    ],
                ],
                'content_manifest' => [
                    'items' => [],
                ],
            ],
        ];

        $manifest = $service->syncFromBuildPlan($scope);
        $slot = $manifest['slots'][$slotId] ?? [];

        self::assertSame($finalUrl, (string)($slot['final_url'] ?? ''));
        self::assertSame('fixture', (string)($slot['source'] ?? ''));
        self::assertSame('generated', (string)($slot['status'] ?? ''));
    }

    public function testRequiredIdentityLogoSlotBriefIsSubjectFirst(): void
    {
        // 锁定 buildRequiredIdentitySlots 写入 manifest 的 slot.brief 是主体优先：
        // 之前以 "Generate the official website logo for X" 开头，会被 AI 解读为
        // 画一个 X 形象（脱离业务）；现在必须以 PRIMARY SUBJECT 作为强契约。
        $service = new AiSiteAssetManifestService();

        $scope = [
            'website_profile' => [
                'site_title' => 'Teenipiya',
                'brief_description' => 'India-focused online card gaming club with APK downloads.',
            ],
        ];

        $manifest = $service->syncFromBuildPlan($scope);
        $slots = \is_array($manifest['slots'] ?? null) ? $manifest['slots'] : [];
        self::assertArrayHasKey('identity:website-logo', $slots);

        $logoBrief = (string)($slots['identity:website-logo']['brief'] ?? '');
        self::assertStringStartsWith('PRIMARY SUBJECT for the logo glyph', $logoBrief);
        self::assertStringContainsString('India-focused online card gaming club', $logoBrief);
        self::assertStringContainsString('Output requirements (HARD):', $logoBrief);
        self::assertStringContainsString('transparent background', $logoBrief);
        self::assertStringNotContainsString('Generate the official website logo for', $logoBrief);

        self::assertArrayHasKey('identity:site-title-icon', $slots);
        $iconBrief = (string)($slots['identity:site-title-icon']['brief'] ?? '');
        self::assertStringStartsWith('PRIMARY SUBJECT for the favicon/title icon', $iconBrief);
        self::assertStringContainsString('India-focused online card gaming club', $iconBrief);
        self::assertStringNotContainsString('Generate the website title icon / favicon for', $iconBrief);
    }

    public function testNonLogoSlotPromptStripsLayoutAndComponentCuesFromReferenceInsights(): void
    {
        $service = new AiSiteAssetManifestService();

        $slot = [
            'slot_id' => 'page:home_page:content-home-page-hero',
            'slot_type' => 'hero_image',
            'page_type' => 'home_page',
            'brief' => 'Home Hero visual.',
            'label' => 'Home Hero',
            'field' => 'image',
        ];
        $scope = [
            'website_profile' => ['site_title' => 'Teenipiya'],
            'reference_image_insights' => [
                'summary' => 'A modern e-commerce website with hero, two-column features, footer.',
                'style_keywords' => ['modern', 'flat illustration'],
                'color_palette' => ['#0ea5e9', '#f59e0b'],
                'layout_cues' => ['hero on top, two columns below, footer with brand'],
                'component_cues' => ['top navigation bar', 'CTA button', 'footer column links'],
                'typography_cues' => ['large condensed sans-serif headlines'],
                'do_not_use' => ['stock photo people in suits'],
            ],
        ];

        $prompt = $service->buildPrompt($slot, $scope);

        self::assertStringContainsString('modern, flat illustration', $prompt);
        self::assertStringContainsString('#0ea5e9, #f59e0b', $prompt);
        self::assertStringContainsString('stock photo people in suits', $prompt);

        self::assertStringNotContainsString('hero on top, two columns below', $prompt);
        self::assertStringNotContainsString('footer column links', $prompt);
        self::assertStringNotContainsString('large condensed sans-serif headlines', $prompt);
        self::assertStringNotContainsString('Reference style summary', $prompt);
        self::assertStringNotContainsString('Reference layout cues', $prompt);
        self::assertStringNotContainsString('Reference component cues', $prompt);
        self::assertStringNotContainsString('Reference typography cues', $prompt);
    }

    public function testLogoSlotPromptKeepsLayoutCuesFromReferenceInsights(): void
    {
        $service = new AiSiteAssetManifestService();

        $slot = [
            'slot_id' => 'identity:website-logo',
            'slot_type' => 'logo_icon',
            'field' => 'logo',
            'brief' => 'Brand logo.',
            'label' => 'Website logo',
        ];
        $scope = [
            'website_profile' => ['site_title' => 'Teenipiya'],
            'reference_image_insights' => [
                'layout_cues' => ['centered glyph badge'],
                'style_keywords' => ['minimal', 'monoline'],
            ],
        ];

        $prompt = $service->buildPrompt($slot, $scope);

        self::assertStringContainsString('centered glyph badge', $prompt);
        self::assertStringContainsString('minimal, monoline', $prompt);
    }
}
