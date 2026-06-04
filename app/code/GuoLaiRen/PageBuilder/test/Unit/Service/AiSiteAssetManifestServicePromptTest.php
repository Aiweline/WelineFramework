<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteAssetManifestService;
use PHPUnit\Framework\TestCase;

/**
 * 閻庢鍣ｇ紓姘躲€侀幋鐐甸檮闁瑰濮撮濠囨煥濞戞瑨澹樻繛瀛橈耿瀹?prompt 闂?PRIMARY SUBJECT"闂婎偄娲ら幊姗€濡磋箛娑欏仺閺夊牃鏅滈弳蹇涙煙绾惧鑵圭紒妤冨枛瀹曟繈妾遍柣锔芥煥鏁堥柛宀嬪缁€鍒ief_description闂? * 婵炴垶鎹侀褔顢氶柆宥嗘櫖閻庡湱鏉痶e_title 婵炲濮撮幊宥囩礊閺冣偓缁?wordmark 闂佸憡鐟ラ崐浠嬪焵椤掆偓閸犳稓妲愰柆宥呰Е閻忕偟鍋撻ˇ褔鏌?block 闂佹悶鍎查崕鎶藉磿濮樺磭鐤€闁告稒鐣埀顒€绻掔划瀣媴鐠団€冲闂佽桨鐒﹂幐鎶藉Υ?mockup闂? * 闂佸搫鐗滈崜姘辩矈鐎靛憡瀚氶柡鍥ュ灪閺佹岸鎮?buildPrompt 闂侀潻璐熼崝瀣箔婢舵劕瑙?slot 缂備緡鍋夐褔鎮楅柨瀣枖閻庯綆鍓氶悾杈┾偓娈垮枛閹碱偊銆冮弽顐ゆ／闁挎梹鍎抽濠囨煛婢跺苯鏋傞柍? */
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
        self::assertStringNotContainsString('Website: Teenipiya', $prompt, 'Brand context must not become visible website text in the image prompt.');
    }

    public function testLogoSlotPromptUsesSubjectFirstContractInsteadOfBrandFirst(): void
    {
        // Logo prompts must lead with the visual subject instead of asking the image model to render the brand name as readable text.

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
                'site_tagline' => 'Play & Win Big 闂?Royal Card Club India',
            ],
        ];

        $prompt = $service->buildPrompt($slot, $scope);

        // 婵炴垶鎹侀濠勭礊鐎ｎ偆闄勯柟瀵稿Т椤斿﹪鐓崶褎鍤囬柕鍡楃箻瀵即顢涘搴″壋婵炴垶鎸撮崑鎾绘偠濞戞鐒跨紒杈ㄧ箖缁嬪寮捄銊х暠婵＄偑鍊涢褏鈧灚锕㈠畷銉╊敃閿涘嫮鎳濋梺鍛婃瀫閵堝洦顫濆┑顔炬嚀閸婃悂宕ｈ閺屻劑顢欓崗鐓庘偓鎶芥偣?        $firstLine = \explode("\n", $prompt)[0] ?? '';
        self::assertStringStartsWith('PRIMARY SUBJECT', $firstLine);
        self::assertStringContainsString('the logo mark/glyph MUST visually depict this business', $firstLine);
        self::assertStringContainsString('India-focused online card gaming club', $firstLine);

        // brand name 闂婎偄娲ら幊姗€濡磋箛娑欌挃鐎广儱娲悰鎾斥槈?wordmark text闂佹寧绋戞總鏃傜箔婢舵劖鍤勯柟瀛樺笧缁嬪﹤鈽?primary subject
        self::assertStringContainsString('Optional brand wordmark text', $prompt);
        self::assertStringContainsString('Teenipiya', $prompt);
        self::assertStringNotContainsString('Brand name (for logo only)', $prompt);

        // logo 闁哄鐗婇幐鎼佸吹椤撱垺鍋嬮柍鍝勫暞閸婄偟绱掗幘鍛存婵炵⒈鍋呯粚鍗炩攽閸喐鐣?
        self::assertStringContainsString('Logo output requirements', $prompt);
        self::assertStringContainsString('transparent alpha background', $prompt);

        // slot.brief 婵?"Generate the official website logo for X" 闂婎偄娲ら幊姗€濡磋箛鏇熷仏妞ゆ劧绲鹃弳顓㈡煕閹邦厾鎳曠紒杈ㄧ箞閺屽棝宕归鐓庤祴婵炴垶鎹侀濠勭礊鐎ｎ喖绀冮柤纰卞墰瀹?
        self::assertStringNotContainsString('Generate the official website logo for "Teenipiya"', $prompt);
        self::assertStringContainsString('Logo specification:', $prompt);

        // 闂婎偄娲ら幊姗€濡磋箛娑樺珘濠㈣泛顭▓鈺冪磼閹惧懐鐣辨い锕€寮跺鑽ゆ暜椤斿墽顦梻渚囧墮閻忔繈宕?prompt 濠电姵娲栭崐璁崇昂
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

        // favicon brief 婵犮垼鍩栧娆掋亹閻愬鈻曢柣鏃堫棑缁犳垵顪冮妶鍡橆潡妞ゎ偓绠撳銊╂偡閺夋寧娅?
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

        // The first line should keep the concrete visual subject ahead of brand context.
        $lines = \explode("\n", $prompt);
        self::assertStringStartsWith('PRIMARY SUBJECT', $lines[0] ?? '');
        self::assertStringContainsString('India-focused online card gaming club', $lines[0] ?? '');

        // Brand context must stay after PRIMARY SUBJECT so it is not rendered as visible image text.
        $brandPos = \strpos($prompt, 'Brand context (do not render as text on the image): Teenipiya');
        $subjectPos = \strpos($prompt, 'PRIMARY SUBJECT');
        self::assertNotFalse($brandPos);
        self::assertNotFalse($subjectPos);
        self::assertGreaterThan($subjectPos, $brandPos, 'PRIMARY SUBJECT must appear before brand context.');
    }

    public function testSyncFromPlanJsonAddsHeroBannerSlotFromPageBlockNodes(): void
    {
        $service = new AiSiteAssetManifestService();

        $scope = [
            'website_profile' => [
                'brief_description' => 'India card gaming club with APK downloads.',
            ],
            'plan_json' => [
                'pages' => [
                    'home_page' => [
                        'home_hero' => [
                            'page_type' => 'home_page',
                            'section_key' => 'home_hero',
                            'block_key' => 'home_hero',
                            'page_flow_role' => 'opening',
                            'goal' => 'Play royal card games tonight',
                            'image_intent' => [
                                'needs_image' => true,
                                'image_role' => 'hero_image',
                                'image_subject' => 'card game lobby hero',
                            ],
                        ],
                        'features' => [
                            'page_type' => 'home_page',
                            'section_key' => 'features',
                            'block_key' => 'features',
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
            ],
        ];

        $manifest = $service->syncFromPlanJson($scope);
        $slots = \is_array($manifest['slots'] ?? null) ? $manifest['slots'] : [];

        self::assertArrayHasKey('page:home_page:content-home-page-home-hero', $slots);
        self::assertArrayHasKey('page:home_page:content-home-page-features', $slots);
        self::assertArrayNotHasKey('home_hero', $slots);
        self::assertArrayNotHasKey('page:home_page:home_hero', $slots);
        self::assertArrayNotHasKey('page:home_page:features', $slots);
        $heroSlot = $slots['page:home_page:content-home-page-home-hero'];
        self::assertSame('hero_image', (string)($heroSlot['slot_type'] ?? ''));
        self::assertStringContainsString('Play royal card games tonight', (string)($heroSlot['label'] ?? ''));

        // banner slot.brief 闂婎偄娲ら幊姗€濡磋箛鏃傤浄?PRIMARY SUBJECT 閻庢鍠掗崑鎾愁熆閹増褰х紒杈ㄧ箖缁嬪顫濋鈧～銈夋偣閸パ屾Ч闁活亝濯界粻娑㈠川濞ｎ兘鍋撹箛娑樼闁惧繒鎳撶粻娑㈡煕閿斿搫濡挎い?1 闁?        $brief = (string)($heroSlot['brief'] ?? '');
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
                'site_title' => 'Neon Card Club',
                'brief_description' => 'India-focused online card gaming club with APK downloads, poker cards, mahjong tiles, chips, and live table UI.',
                'site_tagline' => 'Royal Card Club India',
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

    public function testSyncFromPlanJsonPreservesVerifiedSlotAcrossSamePlanningContext(): void
    {
        $service = new AiSiteAssetManifestService();
        $slotId = 'page:home_page:hero:opening:image';
        $finalUrl = '/pub/media/page-build/ai-generated/example.test/page-home_page-hero-opening-image.png';

        $scope = [
            'website_profile' => [
                'brief_description' => 'A premium Sichuan restaurant website.',
            ],
            'plan_json' => [
                'signature' => 'same-plan-json-signature',
                'pages' => [
                    'home_page' => [
                        'hero' => [
                            'page_type' => 'home_page',
                            'section_key' => 'hero',
                            'block_key' => 'hero',
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
                                    'brief' => 'Duplicate requirement for the same slot.',
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
                ],
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
                        'planning_context_hash' => 'same-plan-json-signature',
                    ],
                ],
            ],
        ];

        $manifest = $service->syncFromPlanJson($scope);
        $slot = $manifest['slots'][$slotId] ?? [];

        self::assertSame($finalUrl, (string)($slot['final_url'] ?? ''));
        self::assertSame('fixture', (string)($slot['source'] ?? ''));
        self::assertSame('generated', (string)($slot['status'] ?? ''));
    }

    public function testRequiredIdentityLogoSlotBriefIsSubjectFirst(): void
    {
        // 闂備礁銇樼粈渚€鎮?buildRequiredIdentitySlots 闂佸憡鍔栭悷銉╁矗?manifest 闂?slot.brief 闂佸搫瀚烽崹顖溾偓闈涙湰閹峰懘骞橀懠顒€鏋犻梺绋跨箰閻楃偟妲?        // 婵炴垶鏌ㄩ鍛櫠閻樺磭顩?"Generate the official website logo for X" 閻庢鍠掗崑鎾愁熆閹増褰х紒杈ㄧ箖鐎电厧顫濋幇浣硅晧 AI 闁荤喐鐟辩紞鈧い鏇犲缁?        // 闂佹眹鍨奸濠勭博鐎涙鈻?X 閻熸粏鍩囬崹濂告寘閸曨垱鏅柛顐到婵海绱掗崒婵愬敽缂佹鍠栧畷婵嬪煡閸涱垳顦梺鎸庣⊕缁嬫捇鐛崶顒€鎹堕柕濞垮劤缁犳垵顪冮妶鍫敽濞?PRIMARY SUBJECT 婵炶揪绲剧划鍫㈡嫻閻斿鍤曢柛婵嗗濞堚晝绱掗幘鍛扮闁?        $service = new AiSiteAssetManifestService();

        $scope = [
            'website_profile' => [
                'site_title' => 'Teenipiya',
                'brief_description' => 'India-focused online card gaming club with APK downloads.',
            ],
        ];

        $manifest = $service->syncFromPlanJson($scope);
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
