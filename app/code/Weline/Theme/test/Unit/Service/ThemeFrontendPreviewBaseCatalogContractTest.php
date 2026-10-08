<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Service\ThemeFrontendPreviewBaseCatalog;

final class ThemeFrontendPreviewBaseCatalogContractTest extends TestCase
{
    public function testLocalShellIsDefaultForDaocharms(): void
    {
        /** @var ThemeFrontendPreviewBaseCatalog $catalog */
        $catalog = ObjectManager::getInstance(ThemeFrontendPreviewBaseCatalog::class);
        $items = $catalog->listForWebsite(158, 'daocharms');
        if ($items === []) {
            self::markTestSkipped('website 158 not resolvable under this bootstrap');
        }

        self::assertSame('local_shell', $items[0]['kind'] ?? null);
        self::assertTrue((bool)($items[0]['is_default'] ?? false));
        self::assertStringContainsString('/~site/daocharms', (string)($items[0]['url'] ?? ''));
        self::assertStringContainsString('/~site', (string)($items[0]['label'] ?? ''));
        self::assertStringContainsString('待发布', (string)($items[0]['hint'] ?? '') . (string)($items[0]['badge'] ?? ''));
        self::assertTrue($catalog->isAllowedBaseUrl((string)$items[0]['url'], 158, 'daocharms'));
        self::assertFalse($catalog->isAllowedBaseUrl('https://evil.example/', 158, 'daocharms'));
    }

    public function testPreviewBasesNoticeExplainsShellVersusExternalDomain(): void
    {
        $controller = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Controller/Backend/ThemeEditor.php'
        );
        self::assertStringContainsString('/~site/{站点}', $controller);
        self::assertStringContainsString('当前后台 Host', $controller);
        self::assertStringContainsString('真实预览靠 Token', $controller);
        $catalog = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/ThemeFrontendPreviewBaseCatalog.php'
        );
        self::assertStringContainsString('本机项目壳（/~site）', $catalog);
        self::assertStringContainsString('applyPreferHostDefault', $catalog);
        self::assertStringContainsString('默认·待发布预览', $catalog);
    }

    public function testControllerAndQueryProviderWirePreviewBases(): void
    {
        $controller = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Controller/Backend/ThemeEditor.php'
        );
        $provider = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/ThemeQueryProvider.php'
        );
        self::assertStringContainsString('function postPreviewBases()', $controller);
        self::assertStringContainsString('preview_base_url', $controller);
        self::assertStringContainsString("'/theme/backend/theme-editor/preview-bases'", $provider);
        self::assertStringContainsString('ThemeFrontendPreviewBaseCatalog', $controller);
        self::assertStringContainsString('resolvePreviewWebsiteFromRequestData($data)', $controller);
        self::assertStringContainsString("\$data['editor_context']['scope']['identity']", $controller);
        self::assertStringContainsString('is_default', $controller);
        self::assertMatchesRegularExpression(
            '/function buildFrontendPreviewUrl\([\s\S]*\?int \$websiteId = null/s',
            $controller,
        );
    }

    public function testGroceryShellBaseIsDefaultAndAllowlisted(): void
    {
        /** @var ThemeFrontendPreviewBaseCatalog $catalog */
        $catalog = ObjectManager::getInstance(ThemeFrontendPreviewBaseCatalog::class);
        $items = $catalog->listForWebsite(544, 'grocery', 'p05113ef3.test.weline.com');
        if ($items === []) {
            self::markTestSkipped('website 544 grocery not resolvable under this bootstrap');
        }

        self::assertSame('local_shell', $items[0]['kind'] ?? null);
        self::assertStringContainsString('/~site/grocery', (string)($items[0]['url'] ?? ''));
        self::assertTrue($catalog->isAllowedBaseUrl((string)$items[0]['url'], 544, 'grocery'));
    }

    public function testGroceryHostOnlyPreferCurrentDomainAsDefault(): void
    {
        /** @var ThemeFrontendPreviewBaseCatalog $catalog */
        $catalog = ObjectManager::getInstance(ThemeFrontendPreviewBaseCatalog::class);
        $items = $catalog->listForWebsite(544, 'grocery', 'grocery.test.weline.com');
        if ($items === []) {
            self::markTestSkipped('website 544 grocery not resolvable under this bootstrap');
        }

        self::assertSame('local_domain', $items[0]['kind'] ?? null);
        self::assertTrue((bool)($items[0]['is_default'] ?? false));
        self::assertStringContainsString('grocery.test.weline.com', (string)($items[0]['url'] ?? ''));
        $shell = null;
        foreach ($items as $item) {
            if (($item['kind'] ?? '') === 'local_shell') {
                $shell = $item;
                break;
            }
        }
        self::assertNotNull($shell);
        self::assertFalse((bool)($shell['is_default'] ?? true));
    }
}
