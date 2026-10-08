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
        self::assertTrue($catalog->isAllowedBaseUrl((string)$items[0]['url'], 158, 'daocharms'));
        self::assertFalse($catalog->isAllowedBaseUrl('https://evil.example/', 158, 'daocharms'));
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
        self::assertStringContainsString(
            'Local theme edit/preview defaults to project-Host /~site/{code}',
            $controller,
        );
        self::assertMatchesRegularExpression(
            '/function buildFrontendPreviewUrl\([\s\S]*\?int \$websiteId = null/s',
            $controller,
        );
    }

    public function testGroceryShellBaseIsDefaultAndAllowlisted(): void
    {
        /** @var ThemeFrontendPreviewBaseCatalog $catalog */
        $catalog = ObjectManager::getInstance(ThemeFrontendPreviewBaseCatalog::class);
        $items = $catalog->listForWebsite(544, 'grocery');
        if ($items === []) {
            self::markTestSkipped('website 544 grocery not resolvable under this bootstrap');
        }

        self::assertSame('local_shell', $items[0]['kind'] ?? null);
        self::assertStringContainsString('/~site/grocery', (string)($items[0]['url'] ?? ''));
        self::assertTrue($catalog->isAllowedBaseUrl((string)$items[0]['url'], 544, 'grocery'));
    }
}
