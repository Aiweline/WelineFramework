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

        self::assertStringContainsString('Block-only visual constraints', $prompt);
        self::assertStringContainsString('DO NOT draw a website mockup', $prompt);
        self::assertStringContainsString('DO NOT include a website header', $prompt);
        self::assertStringContainsString('DO NOT include a website footer', $prompt);
        self::assertStringContainsString('DO NOT draw call-to-action buttons', $prompt);
        self::assertStringContainsString('DO NOT render readable English/Chinese paragraph text', $prompt);
        self::assertStringContainsString('DO NOT show multiple separate page sections stitched together', $prompt);

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
            'execution_blueprint' => [
                'pages' => [
                    'home_page' => [
                        'blocks' => [
                            [
                                'block_key' => 'home_hero',
                                'field_plan' => [
                                    ['field' => 'title', 'sample' => 'Play royal card games tonight'],
                                ],
                            ],
                            ['block_key' => 'features'],
                        ],
                    ],
                ],
            ],
        ];

        $manifest = $service->syncFromBuildPlan($scope);
        $slots = \is_array($manifest['slots'] ?? null) ? $manifest['slots'] : [];

        self::assertArrayHasKey('page:home_page:content-home-page-home-hero', $slots);
        $heroSlot = $slots['page:home_page:content-home-page-home-hero'];
        self::assertSame('hero_image', (string)($heroSlot['slot_type'] ?? ''));
        self::assertStringContainsString('Hero banner background', (string)($heroSlot['label'] ?? ''));

        // banner slot.brief 必须以 PRIMARY SUBJECT 开头，业务诉求必须出现在第 1 行
        $brief = (string)($heroSlot['brief'] ?? '');
        self::assertStringStartsWith('PRIMARY SUBJECT', $brief);
        self::assertStringContainsString('India card gaming club', $brief);
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
        self::assertStringContainsString('Output requirements:', $logoBrief);
        self::assertStringContainsString('transparent alpha background', $logoBrief);
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
