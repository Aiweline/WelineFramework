<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\ThemeHeadChromeCssPack;
use Weline\Theme\Service\ThemeResourceGateway;

/**
 * Head chrome CSS pack: ordered token/UI stacks; theme.css + toast stay independent.
 */
final class ThemeHeadChromeCssPackContractTest extends TestCase
{
    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . ltrim($relative, '/');
        self::assertFileExists($path);
        $content = file_get_contents($path);
        self::assertIsString($content);

        return $content;
    }

    public function testPackServiceKeepsArchitectureAndStyleLayerOutOfTokenStacks(): void
    {
        $src = $this->read('Service/ThemeHeadChromeCssPack.php');
        self::assertStringContainsString('PACK_TOKENS_PRE', $src);
        self::assertStringContainsString('PACK_TOKENS_POST', $src);
        self::assertStringContainsString('PACK_UI', $src);
        self::assertStringContainsString('colors/_light.css', $src);
        self::assertStringContainsString('colors/_ink.css', $src);
        self::assertStringContainsString('variables/_spacing.css', $src);
        self::assertStringContainsString('weline-foundation.css', $src);
        self::assertStringNotContainsString('assets/css/theme.css', $src);
        self::assertStringNotContainsString('storefront-shopper-toast-amazon.css', $src);
        self::assertStringContainsString('theme_css_merge', $src);
        self::assertStringNotContainsString("['css_merge']", $src);
        self::assertStringContainsString('theme-head-chrome-v1', $src);
    }

    public function testGatewayExposesHeadChromeArtifactPath(): void
    {
        $src = $this->read('Service/ThemeResourceGateway.php');
        self::assertStringContainsString('function buildHeadChromeArtifact', $src);
        self::assertStringContainsString('/Weline/Theme/theme-head/', $src);
    }

    public function testTokenPreOrderedBeforeStylesAfterHookInComposedHead(): void
    {
        $head = $this->read('view/theme/frontend/partials/head/assets-suffix.phtml');
        // Emission order in the template body (PHPUnit assertLessThan($expected,$actual) ⇒ $actual < $expected).
        $emitPre = strpos($head, '<?= $tokenPreHtml');
        $hook = strpos($head, 'Weline_Theme::frontend::partials::head::styles-after');
        $emitPost = strpos($head, '<?= $tokenPostHtml');
        self::assertNotFalse($emitPre);
        self::assertNotFalse($hook);
        self::assertNotFalse($emitPost);
        self::assertLessThan($hook, $emitPre, 'tokens-pre emit must precede styles-after hook');
        self::assertLessThan($emitPost, $hook, 'tokens-post emit must follow styles-after hook');
    }

    public function testPackClassConstantsMatchHeadWiring(): void
    {
        self::assertSame('tokens-pre', ThemeHeadChromeCssPack::PACK_TOKENS_PRE);
        self::assertSame('tokens-post', ThemeHeadChromeCssPack::PACK_TOKENS_POST);
        self::assertSame('ui', ThemeHeadChromeCssPack::PACK_UI);
        self::assertTrue(method_exists(ThemeResourceGateway::class, 'buildHeadChromeArtifact'));
    }
}
