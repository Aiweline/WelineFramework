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
            'site_title' => 'AI Site',
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
}
