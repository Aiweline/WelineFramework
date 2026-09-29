<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityDocumentShellBaker;

/**
 * Architect-freeze §4：文档壳 bake 进 shell.phtml（禁 FetchAfter wrap）。
 */
final class DocumentShellBakedIntoShellContractTest extends TestCase
{




    public function testBakeFromHomepageFixtureProducesSingleDocumentRoot(): void
    {
        $homepage = \dirname(__DIR__, 3) . '/view/theme/frontend/layouts/homepage/default.phtml';
        self::assertFileExists($homepage);
        $source = (string)\file_get_contents($homepage);

        $baker = new ThemeLayoutEntityDocumentShellBaker();
        $segments = $baker->bakeFromSource($source, $homepage);
        $shell = $baker->wrap(
            $segments['preamble'],
            "<?php\n\$__chromePath = '/tmp/chrome.phtml';\nif (\\is_string(\$__chromePath) && \$__chromePath !== '' && \\is_file(\$__chromePath)) {\n    include \$__chromePath;\n}\n?>",
            "<!--THEME-LAYOUT-ENTITY-SLOT:content-->\n<div class=\"theme-layout-entity-slot\" data-slot-id=\"content\"></div>\n",
            $segments['postamble'],
        );

        self::assertMatchesRegularExpression('/<!DOCTYPE\\s+html>/i', $shell);
        self::assertMatchesRegularExpression('/<html\\b/i', $shell);
        self::assertMatchesRegularExpression('/<head\\b/i', $shell);
        self::assertMatchesRegularExpression('/<\\/head>/i', $shell);
        self::assertMatchesRegularExpression('/<body\\b/i', $shell);
        self::assertMatchesRegularExpression('/<\\/body>/i', $shell);
        self::assertMatchesRegularExpression('/<\\/html>/i', $shell);
        // docshell.v4: hooks remain source Taglib (relation-only solidify).
        self::assertStringContainsString('$__chromePath', $shell);
        self::assertStringContainsString('chrome.phtml', $shell);
        self::assertDoesNotMatchRegularExpression('/include\\s+\\$__chromePath\\s*;/', $shell);
        self::assertStringContainsString('theme-layout-entity-slot', $shell);
        self::assertStringContainsString('<w:hook>', $shell);
        self::assertStringNotContainsString('Template::class)->getHook(', $shell);

        self::assertSame(1, \preg_match_all('/<html\\b/i', $shell));
        self::assertSame(1, \preg_match_all('/<!DOCTYPE\\b/i', $shell));

        self::assertStringNotContainsString('<w:slot', $shell);
        self::assertStringNotContainsString('<w:widget', $shell);
        // wrap() may still interleave Partials header/footer PHP for chrome bind path;
        // source hooks in preamble/postamble must survive.
        self::assertStringContainsString('Weline_Theme::frontend::layouts::homepage::body-end', $shell);
        self::assertStringContainsString("->renderPartials('frontend', 'header', [], 'default')", $shell);
        self::assertStringContainsString("->renderPartials('frontend', 'footer', [], 'default')", $shell);
        $headerPos = \strpos($shell, "->renderPartials('frontend', 'header'");
        $slotPos = \strpos($shell, 'data-slot-id="content"');
        $footerPos = \strpos($shell, "->renderPartials('frontend', 'footer'");
        self::assertNotFalse($headerPos);
        self::assertNotFalse($slotPos);
        self::assertNotFalse($footerPos);
        self::assertLessThan($slotPos, $headerPos);
        self::assertLessThan($footerPos, $slotPos);
    }

    public function testBakeFromDefaultLayoutAndProductsLayout(): void
    {
        $baker = new ThemeLayoutEntityDocumentShellBaker();
        $default = \dirname(__DIR__, 3) . '/view/theme/frontend/layouts/default/default.phtml';
        $products = \dirname(__DIR__, 4) . '/Product/view/theme/frontend/layouts/products/default.phtml';
        self::assertFileExists($default);
        self::assertFileExists($products);

        foreach ([$default, $products] as $path) {
            $segments = $baker->bakeFromSource((string)\file_get_contents($path), $path);
            $shell = $baker->wrap($segments['preamble'], "<?php\n\$__chromePath = '/tmp/chrome.phtml';\n?>", "slot-body\n", $segments['postamble']);
            self::assertMatchesRegularExpression('/<!DOCTYPE\\b/i', $shell, $path);
            self::assertStringContainsString("->renderPartials('frontend', 'header', [], 'default')", $shell);
            self::assertStringContainsString("->renderPartials('frontend', 'footer', [], 'default')", $shell);
            self::assertDoesNotMatchRegularExpression('/include\\s+\\$__chromePath\\s*;/', $shell);
            self::assertStringNotContainsString('<w:slot', $shell);
            self::assertStringNotContainsString('<w:widget', $shell);
        }
    }

    public function testAlgoVersionConstantStableForDigest(): void
    {
        self::assertSame('docshell.v4', ThemeLayoutEntityDocumentShellBaker::ALGO_VERSION);
    }
}
