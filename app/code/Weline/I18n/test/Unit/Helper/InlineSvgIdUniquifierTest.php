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

    public function testUniquifyRewritesIdsAndHrefRefsIndependentlyPerCall(): void
    {
        $svg = <<<'SVG'
<svg id="flag-icons-cn" viewBox="0 0 640 480">
  <defs><path id="cn-a" d="M0 0"/></defs>
  <use xlink:href="#cn-a"/>
</svg>
SVG;

        $first = InlineSvgIdUniquifier::uniquify($svg);
        $second = InlineSvgIdUniquifier::uniquify($svg);

        self::assertStringContainsString('id="flag-icons-cn-u1"', $first);
        self::assertStringContainsString('id="cn-a-u1"', $first);
        self::assertStringContainsString('xlink:href="#cn-a-u1"', $first);
        self::assertStringNotContainsString('id="flag-icons-cn"', $first);
        self::assertStringNotContainsString('href="#cn-a"', $first);

        self::assertStringContainsString('id="flag-icons-cn-u2"', $second);
        self::assertStringContainsString('id="cn-a-u2"', $second);
        self::assertStringContainsString('xlink:href="#cn-a-u2"', $second);

        self::assertNotSame($first, $second);
    }

    public function testEmptyAndIdLessMarkupPassThrough(): void
    {
        self::assertSame('', InlineSvgIdUniquifier::uniquify(''));
        self::assertSame('<svg viewBox="0 0 1 1"/>', InlineSvgIdUniquifier::uniquify('<svg viewBox="0 0 1 1"/>'));
    }
}
