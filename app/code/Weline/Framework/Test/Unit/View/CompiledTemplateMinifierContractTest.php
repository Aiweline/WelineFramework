<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Framework\View\CompiledTemplateMinifier;

final class CompiledTemplateMinifierContractTest extends TestCase
{
    public function testCollapseHtmlWhitespaceKeepsPreAndCollapsesAdjacent(): void
    {
        $method = new ReflectionMethod(CompiledTemplateMinifier::class, 'collapseHtmlWhitespace');
        $method->setAccessible(true);

        self::assertSame(
            '<pre>  keep   spaces  </pre>',
            $method->invoke(null, '<pre>  keep   spaces  </pre>')
        );

        $collapsed = $method->invoke(null, "<div>  Hello   World  </div>\n\n\n");
        self::assertStringContainsString('<div> Hello World </div>', $collapsed);
        self::assertStringNotContainsString('  Hello   World  ', $collapsed);
    }

    public function testMinifyKeepsHashCommentAndIsIdentityInDev(): void
    {
        $src = "<?php /* hash:abc123-10 */ ?>\n\n  <div>  Hello   World  </div>\n\n";
        $out = CompiledTemplateMinifier::minify($src);

        if (\defined('DEV') && DEV) {
            self::assertFalse(CompiledTemplateMinifier::shouldMinify());
            self::assertSame($src, $out);

            return;
        }

        self::assertStringContainsString('hash:abc123-10', $out);
        self::assertStringContainsString('<div> Hello World </div>', $out);
    }
}
