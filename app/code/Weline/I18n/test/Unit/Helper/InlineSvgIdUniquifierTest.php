<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Helper\InlineSvgIdUniquifier;

final class InlineSvgIdUniquifierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        InlineSvgIdUniquifier::resetSequence();
    }

    public function testUniquifyRewritesIdsAndHrefRefsAcrossIndependentRenderContexts(): void
    {
        $svg = <<<'SVG'
<svg id="flag-icons-cn" viewBox="0 0 640 480">
  <defs><path id="cn-a" d="M0 0"/></defs>
  <use xlink:href="#cn-a"/>
</svg>
SVG;

        $first = InlineSvgIdUniquifier::uniquify($svg);
        self::assertSame(1, preg_match('/id="flag-icons-cn-u([0-9a-f]{24})"/', $first, $firstMatch));
        $firstNamespace = $firstMatch[1];
        self::assertStringContainsString('id="cn-a-u' . $firstNamespace . '"', $first);
        self::assertStringContainsString('xlink:href="#cn-a-u' . $firstNamespace . '"', $first);
        self::assertStringNotContainsString('id="flag-icons-cn"', $first);
        self::assertStringNotContainsString('href="#cn-a"', $first);

        // Independent Hook/FPC renderers can restart process-local counters.
        // Resetting here reproduces the old cross-context collision boundary.
        InlineSvgIdUniquifier::resetSequence();
        $second = InlineSvgIdUniquifier::uniquify($svg);
        self::assertSame(1, preg_match('/id="flag-icons-cn-u([0-9a-f]{24})"/', $second, $secondMatch));
        $secondNamespace = $secondMatch[1];
        self::assertStringContainsString('id="cn-a-u' . $secondNamespace . '"', $second);
        self::assertStringContainsString('xlink:href="#cn-a-u' . $secondNamespace . '"', $second);

        self::assertNotSame($firstNamespace, $secondNamespace);
        self::assertNotSame($first, $second);
    }

    public function testEmptyAndIdLessMarkupPassThrough(): void
    {
        self::assertSame('', InlineSvgIdUniquifier::uniquify(''));
        self::assertSame('<svg viewBox="0 0 1 1"/>', InlineSvgIdUniquifier::uniquify('<svg viewBox="0 0 1 1"/>'));
    }
}
