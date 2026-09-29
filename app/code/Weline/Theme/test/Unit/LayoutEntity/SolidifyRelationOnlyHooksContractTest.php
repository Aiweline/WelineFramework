<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityDocumentShellBaker;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityMaterializer;

/**
 * Solidify is relation-only: layout↔widget. Hooks / unrelated Taglib stay source markup.
 */
final class SolidifyRelationOnlyHooksContractTest extends TestCase
{
    public function testDocumentShellBakerDoesNotBakeHooksToPhp(): void
    {
        $path = \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityDocumentShellBaker.php';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);

        self::assertStringContainsString("ALGO_VERSION = 'docshell.v4'", $src);
        self::assertStringContainsString('Leave w:hook', $src);
        self::assertStringContainsString('document_shell_forbid_hook_bake', $src);
        // rewrite fringe must not convert hooks; phpGetHook must throw if called.
        $rewritePos = \strpos($src, 'function rewriteDocumentShellMarkup');
        $phpGetHookPos = \strpos($src, 'function phpGetHook');
        self::assertNotFalse($rewritePos);
        self::assertNotFalse($phpGetHookPos);
        $rewriteFn = \substr($src, $rewritePos, $phpGetHookPos - $rewritePos);
        self::assertStringNotContainsString('phpGetHook', $rewriteFn);
        self::assertStringContainsString('w:slot', $rewriteFn);
    }

    public function testBakeFromSourceKeepsHookTags(): void
    {
        $source = <<<'HTML'
<!DOCTYPE html>
<html lang="en"><head><title>t</title>
<w:hook>Weline_Theme::frontend::layouts::homepage::head-after</w:hook>
</head>
<body id="top">
<w:hook>Weline_Theme::frontend::layouts::homepage::body-start</w:hook>
<main class="weline-main-content homepage-main" id="homepage-main">
<w:slot id="homepage-hero" name="Hero"><w:widget type="banner" name="hero-slider" /></w:slot>
</main>
<w:hook>Weline_Theme::frontend::layouts::homepage::body-end</w:hook>
<w:hook>Weline_Theme::frontend::layouts::base::body-end</w:hook>
</body></html>
HTML;
        $baker = new ThemeLayoutEntityDocumentShellBaker();
        $segments = $baker->bakeFromSource($source, '(memory-homepage)');

        self::assertStringContainsString(
            '<w:hook>Weline_Theme::frontend::layouts::homepage::head-after</w:hook>',
            $segments['preamble'],
        );
        self::assertStringContainsString(
            '<w:hook>Weline_Theme::frontend::layouts::homepage::body-end</w:hook>',
            $segments['postamble'],
        );
        self::assertStringContainsString(
            '<w:hook>Weline_Theme::frontend::layouts::base::body-end</w:hook>',
            $segments['postamble'],
        );
        self::assertStringNotContainsString('Template::class)->getHook(', $segments['preamble']);
        self::assertStringNotContainsString('Template::class)->getHook(', $segments['postamble']);
        // Slots stripped from shell fringe (body owns them).
        self::assertStringNotContainsString('<w:slot', $segments['preamble']);
        self::assertStringNotContainsString('<w:slot', $segments['postamble']);
    }


}
