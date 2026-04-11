<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Model\VirtualTheme;
use GuoLaiRen\PageBuilder\Service\AiSitePageBlueprintService;
use GuoLaiRen\PageBuilder\Service\AiSitePageComponentGenerationService;
use GuoLaiRen\PageBuilder\Service\AiSiteVirtualThemeService;
use GuoLaiRen\PageBuilder\Service\AI\CodeFixer;
use GuoLaiRen\PageBuilder\Service\AI\CodeValidator;
use GuoLaiRen\PageBuilder\Service\AI\FrameworkBuilder;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;

/**
 * PHPUnit 下 AiSitePageComponentGenerationService 走桩数据（不调用真实 AI），
 * 验证 ensureAiGeneratedVirtualTheme 能落库并返回完整 page_type_layouts。
 */
final class AiSiteVirtualThemeGenerationTest extends TestCase
{
    public function testEnsureAiGeneratedVirtualThemeCreatesThemeAndLayoutsUnderPhpUnit(): void
    {
        $suffix = \bin2hex(\random_bytes(4));
        $siteTitle = 'PHPUnit VT ' . $suffix;

        $scope = [
            'site_title' => $siteTitle,
            'brief_description' => 'Unit test virtual theme generation scope.',
            'page_types' => [Page::TYPE_HOME, Page::TYPE_ABOUT],
            'virtual_theme_id' => 0,
        ];
        $websiteProfile = [
            'site_title' => $siteTitle,
            'brief_description' => $scope['brief_description'],
            'site_tagline' => 'Tag',
        ];
        $pageTypes = [Page::TYPE_HOME, Page::TYPE_ABOUT];

        $generation = new AiSitePageComponentGenerationService(
            frameworkBuilder: new FrameworkBuilder(),
            codeFixer: new CodeFixer(),
            codeValidator: new CodeValidator(),
        );
        $service = new AiSiteVirtualThemeService(new AiSitePageBlueprintService(), $generation);

        $result = $service->ensureAiGeneratedVirtualTheme($scope, $websiteProfile, $pageTypes, [], 0);

        $virtualThemeId = (int)($result['virtual_theme_id'] ?? 0);
        self::assertGreaterThan(0, $virtualThemeId);
        self::assertArrayHasKey('page_type_layouts', $result);
        self::assertArrayHasKey(Page::TYPE_HOME, $result['page_type_layouts']);
        self::assertArrayHasKey(Page::TYPE_ABOUT, $result['page_type_layouts']);

        $homeLayout = $result['page_type_layouts'][Page::TYPE_HOME];
        self::assertSame('header/ai-site-header', \trim((string)($homeLayout['header']['component'] ?? '')));
        self::assertSame('footer/ai-site-footer', \trim((string)($homeLayout['footer']['component'] ?? '')));
        self::assertNotEmpty($homeLayout['content'] ?? []);
        self::assertSame('1.0', (string)($homeLayout['version'] ?? ''));

        /** @var VirtualTheme $themeModel */
        $themeModel = ObjectManager::getInstance(VirtualTheme::class);
        $loaded = clone $themeModel;
        $loaded->clearData()->clearQuery()->load($virtualThemeId);
        self::assertSame($virtualThemeId, (int)$loaded->getId());

        $config = $loaded->getConfig();
        self::assertIsArray($config);
        self::assertArrayHasKey('virtual_page_layouts', $config);
        self::assertIsArray($config['virtual_page_layouts']);
    }
}
