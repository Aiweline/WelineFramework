<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\AiSitePageBlueprintService;
use PHPUnit\Framework\TestCase;

class AiSitePageBlueprintServiceTest extends TestCase
{
    public function testBuildPageBlueprintProducesDifferentDescriptionsAndMultipleSectionsPerPage(): void
    {
        $service = new AiSitePageBlueprintService();
        $scope = [
            'brief_description' => '我想做一个印度市场的棋牌网站，推广apk，并且希望首页突出下载转化。',
            'user_description' => '我想做一个印度市场的棋牌网站，推广apk，并且希望首页突出下载转化。',
            'virtual_pages_by_type' => [
                Page::TYPE_HOME => [
                    'ai_description' => '我想做一个印度市场的棋牌网站，推广apk，并且希望首页突出下载转化。',
                ],
                Page::TYPE_ABOUT => [
                    'ai_description' => '我想做一个印度市场的棋牌网站，推广apk，并且希望团队与品牌故事更可信。',
                ],
            ],
        ];
        $websiteProfile = [
            'site_title' => '印度棋牌 APK 平台',
            'site_tagline' => '',
            'brief_description' => '我想做一个印度市场的棋牌网站，推广apk。',
            'target_domain' => '',
        ];

        $homeBlueprint = $service->buildPageBlueprint(Page::TYPE_HOME, $scope, $websiteProfile);
        $aboutBlueprint = $service->buildPageBlueprint(Page::TYPE_ABOUT, $scope, $websiteProfile);

        self::assertNotSame($homeBlueprint['ai_description'], $aboutBlueprint['ai_description']);
        self::assertGreaterThan(1, \count($homeBlueprint['sections']));
        self::assertGreaterThan(1, \count($aboutBlueprint['sections']));
        self::assertNotSame(
            $homeBlueprint['sections'][1]['code'] ?? '',
            $aboutBlueprint['sections'][1]['code'] ?? ''
        );
        self::assertSame('印度棋牌 APK 平台', $homeBlueprint['site_display_name']);
    }

    public function testResolveSiteDisplayNameUsesCustomerSiteTitleNotDescription(): void
    {
        $service = new AiSitePageBlueprintService();
        $scope = [
            'site_title' => 'Teenipiya',
            'user_description' => '# ROLE You are a senior web designer building a gaming entertainment site.',
            'brief_description' => '# ROLE You are a senior web designer building a gaming entertainment site.',
            'build_plan_v2' => [
                'site_brief' => [
                    'site_name' => '# ROLE You are a senior web designer',
                ],
                'source_of_truth' => [
                    'user_requirements' => [
                        'site_name' => '# ROLE You are a senior web designer',
                    ],
                ],
            ],
        ];
        $websiteProfile = [
            'site_title' => 'Teenipiya',
            'brief_description' => '# ROLE You are a senior web designer building a gaming entertainment site.',
        ];

        self::assertSame('Teenipiya', $service->resolveUserSiteTitle($websiteProfile, $scope));
        self::assertSame('Teenipiya', $service->resolveSiteDisplayName($websiteProfile, $scope));
    }

    public function testResolveSiteDisplayNameDoesNotDeriveTitleFromUserDescription(): void
    {
        $service = new AiSitePageBlueprintService();
        $scope = [
            'user_description' => '# ROLE You are a senior web designer building a gaming entertainment site.',
            'brief_description' => '# ROLE You are a senior web designer building a gaming entertainment site.',
        ];
        $websiteProfile = [
            'brief_description' => '# ROLE You are a senior web designer building a gaming entertainment site.',
        ];

        self::assertSame('', $service->resolveUserSiteTitle($websiteProfile, $scope));
        self::assertSame('', $service->resolveSiteDisplayName($websiteProfile, $scope));
    }

    public function testBuildPageBlueprintCarriesSectionRefinementIntoResult(): void
    {
        $service = new AiSitePageBlueprintService();
        $scope = [
            'brief_description' => '打造一个更有转化力的联系我们页面。',
            'virtual_pages_by_type' => [
                Page::TYPE_CONTACT => [
                    'section_refinements' => [
                        'content/contact-page-hero' => '把这一段改成更强调商务合作和快速响应。',
                    ],
                ],
            ],
        ];
        $websiteProfile = [
            'site_title' => 'Contact Hub',
            'brief_description' => '打造一个更有转化力的联系我们页面。',
        ];

        $blueprint = $service->buildPageBlueprint(Page::TYPE_CONTACT, $scope, $websiteProfile);
        $heroSection = $blueprint['sections'][0] ?? [];

        self::assertSame(
            '把这一段改成更强调商务合作和快速响应。',
            (string)($blueprint['section_refinements']['content/contact-page-hero'] ?? '')
        );
        self::assertStringContainsString(
            '重点微调',
            (string)($heroSection['config']['description'] ?? '')
        );
    }

    public function testBuildPageBlueprintUsesNativeBlogComponents(): void
    {
        $service = new AiSitePageBlueprintService();
        $websiteProfile = [
            'site_title' => 'Blog Site',
            'brief_description' => 'A site with articles and category pages.',
        ];

        $expectations = [
            Page::TYPE_BLOG_LIST => 'blog-list',
            Page::TYPE_BLOG_CATEGORY => 'blog-category',
            Page::TYPE_BLOG => 'blog-detail',
        ];

        foreach ($expectations as $pageType => $componentCode) {
            $blueprint = $service->buildPageBlueprint($pageType, [], $websiteProfile);
            $sections = $blueprint['sections'] ?? [];

            self::assertCount(1, $sections);
            self::assertSame('native-blog', $sections[0]['key'] ?? '');
            self::assertSame($componentCode, $sections[0]['code'] ?? '');
            self::assertSame('native-blog', $sections[0]['template'] ?? '');
            self::assertArrayHasKey('config', $sections[0]);
            self::assertStringNotContainsString('content/blog', $sections[0]['code'] ?? '');
        }
    }

    public function testBuildPageBlueprintPolicySectionsUseDistinctKeys(): void
    {
        $service = new AiSitePageBlueprintService();

        $blueprint = $service->buildPageBlueprint(Page::TYPE_REFUND_POLICY, [
            'brief_description' => 'Explain refund rules clearly for visitors before they download the app.',
        ], [
            'site_title' => 'Teenipiya',
            'brief_description' => 'Explain refund rules clearly for visitors before they download the app.',
        ]);

        $keys = \array_values(\array_map(
            static fn(array $section): string => (string)($section['key'] ?? ''),
            \is_array($blueprint['sections'] ?? null) ? $blueprint['sections'] : []
        ));

        self::assertSame(['hero', 'coverage', 'rights', 'cta'], $keys);
        self::assertSame($keys, \array_values(\array_unique($keys)));
    }

    public function testBuildPageBlueprintHomeUsesCanonicalDownloadSiteBlockKeys(): void
    {
        $service = new AiSitePageBlueprintService();

        $blueprint = $service->buildPageBlueprint(Page::TYPE_HOME, [
            'brief_description' => '印度市场的棋牌网站，推广棋牌apk下载的seo网站。',
            'user_description' => '印度市场的棋牌网站，推广棋牌apk下载的seo网站。',
        ], [
            'site_title' => 'Teenipiya',
            'brief_description' => '印度市场的棋牌网站，推广棋牌apk下载的seo网站。',
        ]);

        $keys = \array_values(\array_map(
            static fn(array $section): string => (string)($section['key'] ?? ''),
            \is_array($blueprint['sections'] ?? null) ? $blueprint['sections'] : []
        ));

        self::assertSame(['hero_download', 'game_showcase_or_features', 'seo_faq', 'final_download_cta'], $keys);
    }

    public function testBuildPageBlueprintDoesNotLeakInternalBriefOrPromptMarkers(): void
    {
        $service = new AiSitePageBlueprintService();
        $rawBrief = '我想做一个印度市场的棋牌网站，推广apk，并且希望首页突出下载转化。';
        $scope = [
            'brief_description' => $rawBrief,
            'user_description' => $rawBrief,
            'virtual_pages_by_type' => [
                Page::TYPE_HOME => [
                    'ai_description' => "首页 页面重点：{$rawBrief}\n站点简报：{$rawBrief}",
                ],
            ],
        ];
        $websiteProfile = [
            'site_title' => 'AI Site',
            'site_tagline' => 'Fast play, clear start',
            'brief_description' => $rawBrief,
            'target_domain' => '',
        ];

        $homeBlueprint = $service->buildPageBlueprint(Page::TYPE_HOME, $scope, $websiteProfile);
        $heroSection = $homeBlueprint['sections'][0] ?? [];
        $heroDescription = (string)($heroSection['config']['description'] ?? '');

        self::assertStringNotContainsString('我想做', $homeBlueprint['ai_description']);
        self::assertStringNotContainsString('页面重点', $homeBlueprint['ai_description']);
        self::assertStringNotContainsString('站点简报', $homeBlueprint['ai_description']);
        self::assertStringNotContainsString('推广apk', $homeBlueprint['ai_description']);
        self::assertStringContainsString('印度市场', $homeBlueprint['ai_description']);
        self::assertStringNotContainsString('我想做', $heroDescription);
        self::assertStringNotContainsString('推广apk', $heroDescription);
    }

    public function testBuildPageBlueprintLocalizesDefaultPageLabelForEnglishLocale(): void
    {
        $service = new AiSitePageBlueprintService();

        $blueprint = $service->buildPageBlueprint(Page::TYPE_ABOUT, [
            'default_locale' => 'en_US',
        ], [
            'site_title' => 'Teenipiya',
            'default_locale' => 'en_US',
            'brief_description' => 'A card game apk website for India users.',
        ]);

        self::assertSame('About', $blueprint['page_label']);
        self::assertSame('About', $blueprint['page_title']);
        self::assertSame('About | Teenipiya', $blueprint['meta_title']);
    }
}
