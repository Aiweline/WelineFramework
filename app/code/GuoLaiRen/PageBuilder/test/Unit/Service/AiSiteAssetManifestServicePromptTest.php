<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteAssetManifestService;
use PHPUnit\Framework\TestCase;

/**
 * 闁诲孩顔栭崳锝囩磽濮樿翰鈧線骞嬮悙鐢告闂佺懓顕慨鎾敊婵犲洦鐓ユ繛鎴炵懆婢规ɑ绻涚€涙﹫鑰跨€?prompt 闂?PRIMARY SUBJECT"闂傚鍋勫ú銈夊箠濮椻偓婵＄绠涘☉娆忎缓闁哄鐗冮弲婊堝汲韫囨稒鐓欑痪鎯ь儐閼靛湱绱掑Δ鍐ㄦ灈鐎规洘绻堝閬嶆煟閿旇姤鐓ラ弫鍫ユ煕瀹€瀣洭缂佲偓閸掝摳ief_description闂? * 濠电偞鍨堕幑渚€顢氳椤㈡岸鏌嗗鍡樻珫闁诲骸婀遍弶鐥秂_title 濠电偛顕慨鎾箠瀹ュ洨绀婇柡鍐ｅ亾缂?wordmark 闂備礁鎲￠悷銉╁磹娴犲鐒垫い鎺嗗亾闁哥姵绋撳Σ鎰版焼瀹ュ懓袝闁诲繒鍋熼崑鎾凰囪閺?block 闂備焦鎮堕崕鏌ュ磿閹惰棄纾挎慨妯虹－閻も偓闂佸憡绋掗悾顏堝焵椤掆偓缁绘帞鍒掔€ｎ偅濯撮悹鍥ｂ偓鍐差棟闂備浇妗ㄩ悞锕傚箰閹惰棄违?mockup闂? * 闂備礁鎼悧婊堝礈濮樿京鐭堥悗闈涙啞鐎氭岸鏌￠崶銉ョ仾闁轰焦宀搁幃?buildPrompt 闂備線娼荤拹鐔煎礉鐎ｎ剛绠斿鑸靛姇鐟?slot 缂傚倷绶￠崑澶愵敋瑜旈幃妤呮煥鐎ｎ兘鏋栭柣搴秵閸撴岸鎮炬潏鈹惧亾濞堝灝鏋涢柟纰卞亰閵嗗啴寮介銈嗭紡闂佹寧姊归崕鎶筋敊婵犲洦鐓涘璺鸿嫰閺嬪倿鏌? */
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
        $firstLine = \explode("\n", $prompt)[0] ?? '';

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
                'site_tagline' => 'Play & Win Big 闂?Royal Card Club India',
            ],
        ];

        $prompt = $service->buildPrompt($slot, $scope);
        $firstLine = \explode("\n", $prompt)[0] ?? '';

        // 濠电偞鍨堕幑渚€顢欐繝鍕閻庯綆鍋嗛梽鍕煙鐎电孝妞ゆ柨锕悡顐﹀炊瑜庨崵鍥煏閸℃绠荤€殿喕鍗抽、娑橆煥鎼粹€冲濠电偞鍨堕幐鎾磻閹剧粯鍋犳繛鎴烆焽閻掕法绱掓潏銊х畺缂佸顦靛顒傛崉閵娧呮殸濠碉紕鍋戦崐娑㈩敋瑜忛埀顒€鐏氶敃銏犵暦閵夆晩鏁冮柨娑樺閹虫繈姊洪崨濠冪€柕鍫濇处椤繂鈹戦鐐殌闁稿﹥鎮傚畷锝堫樄闁哄被鍔戦、娆撳礂閻撳簶鍋撻幎鑺ュ仯?        $firstLine = \explode("\n", $prompt)[0] ?? '';
        self::assertStringStartsWith('PRIMARY SUBJECT', $firstLine);
        self::assertStringContainsString('the logo mark/glyph MUST visually depict this business', $firstLine);
        self::assertStringContainsString('India-focused online card gaming club', $firstLine);

        // brand name 闂傚鍋勫ú銈夊箠濮椻偓婵＄绠涘☉娆屾寖閻庡箍鍎卞ú顓㈡偘閹炬枼妲?wordmark text闂備焦瀵х粙鎴炵附閺冨倻绠斿鑸靛姈閸ゅ嫰鏌熺€涙ê绗х紒瀣工閳?primary subject
        self::assertStringContainsString('Optional brand wordmark text', $prompt);
        self::assertStringContainsString('Teenipiya', $prompt);
        self::assertStringNotContainsString('Brand name (for logo only)', $prompt);

        // logo 闂佸搫顦悧濠囧箰閹间礁鍚规い鎾卞灪閸嬪鏌嶉崫鍕殲闁稿﹦鍋熺槐鎺楀箻閸涘瓨顎嶅┑鐐碘拡閸嬪懐绮氶崡鐐╂斀闁割偆鍠愰悾?
        self::assertStringContainsString('Logo output requirements', $prompt);
        self::assertStringContainsString('transparent alpha background', $prompt);

        // slot.brief 濠?"Generate the official website logo for X" 闂傚鍋勫ú銈夊箠濮椻偓婵＄绠涢弴鐔蜂粡濡炪倖鍔х徊楣冨汲椤撱垺鐓曢柟閭﹀幘閹虫洜绱掓潏銊х疄闁哄苯妫濆畷褰掝敊閻撳氦绁村┑鐐村灦閹逛線顢欐繝鍕閻庯綆鍠栫粈鍐煠绾板崬澧扮€?
        self::assertStringNotContainsString('Generate the official website logo for "Teenipiya"', $prompt);
        self::assertStringContainsString('Logo specification:', $prompt);

        // 闂傚鍋勫ú銈夊箠濮椻偓婵＄绠涘☉妯虹彉婵犮垼娉涢…顒€鈻撻埡鍐＜闁规儳鎳愰悾杈ㄣ亜閿曗偓瀵泛顭囬懡銈嗘殰妞ゆ柨澧介ˇ顕€姊绘笟鍥у闁诲繑绻堝畷?prompt 婵犵數濮靛ú鏍磹鐠佸磭鏄?
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

        // favicon brief 濠电姰鍨奸崺鏍ь焽濞嗘帇浜归柣鎰嚟閳绘洟鏌ｉ弮鍫缂佺姵鍨甸—鍐Χ閸℃﹩娼″銈庡亾缁犳挸顕ｉ妸鈺傚仭闁哄瀵у▍?
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

    public function testSyncFromPlanJsonAddsHeroBannerSlotFromPageBlocks(): void
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
        $brief = (string)($heroSlot['brief'] ?? '');

        // banner slot.brief 闂傚鍋勫ú銈夊箠濮椻偓婵＄绠涢弮鍌ゆ祫?PRIMARY SUBJECT 闁诲孩顔栭崰鎺楀磻閹炬剚鐔嗛柟顖滃瑜把呯磼鏉堛劎绠栫紒瀣槸椤繈顢楅埀顒勶綖閵堝鍋ｉ柛銉戝本效闂佹椿浜濇刊鐣岀不濞戙垹宸濇繛锝庡厴閸嬫捁绠涘☉妯碱槱闂佹儳绻掗幊鎾剁不濞戙垺鐓曢柨鏂挎惈婵℃寧銇?1 闂?        $brief = (string)($heroSlot['brief'] ?? '');
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

    public function testRequiredThemeLogoGenerationSlotBriefsAreSubjectFirst(): void
    {
        $service = new AiSiteAssetManifestService();
        // 闂傚倷绀侀妵妯肩矆娓氣偓閹?buildRequiredIdentitySlots 闂備礁鎲￠崝鏍偡閵夆晛鐭?manifest 闂?slot.brief 闂備礁鎼€氱兘宕归婧惧亾闂堟稒婀伴柟宄版嚇楠炴﹢鎳犻鈧弸鐘绘⒑缁嬭法绠伴柣妤冨仧濡?        // 濠电偞鍨堕弻銊╊敄閸涱喗娅犻柣妯虹－椤?"Generate the official website logo for X" 闁诲孩顔栭崰鎺楀磻閹炬剚鐔嗛柟顖滃瑜把呯磼鏉堛劎绠栭悗鐢靛帶椤繈骞囨担纭呮櫑 AI 闂佽崵鍠愰悷杈╃礊閳ь剚銇勯弴鐘差暭缂?        // 闂備焦鐪归崹濂割敊婵犲嫮鍗氶悗娑欘焽閳?X 闁荤喐绮忛崺鍥垂婵傚憡瀵橀柛鏇ㄥ灡閺咁剟鏌涢顒傚埌濠殿喖娴风槐鎺楀磼濠垫劕鏁界紓浣诡殘閸犳牕鐣峰┑瀣叀闁告侗鍨抽ˇ顐︽⒑閹稿海鈯曠紒瀣崌閻涱噣宕堕鈧幑鍫曟煏婵炲灝鍔ょ紒鐘冲灥椤啴濡堕崼顐㈡暯婵?PRIMARY SUBJECT 濠电偠鎻徊鍓у垝閸垺瀚婚柣鏂款殠閸ゆ洟鏌涘┑鍡楊伌婵炲牃鏅濈槐鎺楀箻閸涙壆顦伴梺?        $service = new AiSiteAssetManifestService();

        $scope = [
            'website_profile' => [
                'site_title' => 'Teenipiya',
                'brief_description' => 'India-focused online card gaming club with APK downloads.',
            ],
        ];

        $manifest = $service->syncFromPlanJson($scope);
        $slots = \is_array($manifest['slots'] ?? null) ? $manifest['slots'] : [];
        self::assertArrayNotHasKey('identity:website-logo', $slots);
        self::assertArrayNotHasKey('identity:site-title-icon', $slots);

        for ($number = 1; $number <= 4; $number++) {
            $slotId = 'plan:theme:logo_generation:option_' . $number;
            self::assertArrayHasKey($slotId, $slots);
            $logoBrief = (string)($slots[$slotId]['brief'] ?? '');
            self::assertStringStartsWith('PRIMARY SUBJECT for the logo glyph', $logoBrief);
            self::assertStringContainsString('India-focused online card gaming club', $logoBrief);
            self::assertStringContainsString('Output requirements (HARD):', $logoBrief);
            self::assertStringContainsString('transparent background', $logoBrief);
            self::assertStringNotContainsString('Generate the official website logo for', $logoBrief);
        }
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
