<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Blog\Service\BlogTranslationHtml;

/**
 * T3 验收：HTML 文本节点切分 / 回填 不得破坏标签、属性与代码块。
 */
final class BlogTranslationHtmlTest extends TestCase
{
    public function testTextNodesSkipsMarkupAndCode(): void
    {
        $html = '<p>你好 <strong>世界</strong></p><script>var a="你好";</script><pre>你好</pre>';
        $this->assertSame(['你好', '世界'], BlogTranslationHtml::textNodes($html));
    }

    public function testRebuildKeepsTagsAndAttributes(): void
    {
        $html = '<a href="/x?a=1" title="你好">你好</a> <em>世界</em>';
        $out = BlogTranslationHtml::rebuild($html, ['你好' => 'Hello', '世界' => 'World']);
        $this->assertSame('<a href="/x?a=1" title="你好">Hello</a> <em>World</em>', $out);
    }

    public function testRebuildLeavesCodeBlocksUntouched(): void
    {
        $html = '<pre>你好</pre><code>你好</code><style>.a{}</style>';
        $this->assertSame($html, BlogTranslationHtml::rebuild($html, ['你好' => 'Hello']));
    }

    public function testRebuildPreservesSurroundingWhitespace(): void
    {
        $this->assertSame("<p>\n  Hello\n</p>", BlogTranslationHtml::rebuild("<p>\n  你好\n</p>", ['你好' => 'Hello']));
    }

    public function testEmptyInput(): void
    {
        $this->assertSame([], BlogTranslationHtml::textNodes(''));
        $this->assertSame('', BlogTranslationHtml::rebuild('', ['a' => 'b']));
    }

    public function testSlugFor(): void
    {
        $this->assertSame('luoshen-zhiju-en', BlogTranslationHtml::slugFor('luoshen-zhiju', 'en_US'));
        $this->assertSame('luoshen-zhiju-en-2', BlogTranslationHtml::slugFor('luoshen-zhiju', 'en_US', 1));
        $this->assertSame('luoshen-zhiju-en-3', BlogTranslationHtml::slugFor('luoshen-zhiju', 'en_US', 2));
        $this->assertSame('luoshen-zhiju-fr', BlogTranslationHtml::slugFor('LuoShen-ZhiJu', 'fr_FR'));
    }
}
