<?php

declare(strict_types=1);

/**
 * Parser 标签层测试（P1 第一刀）。
 *
 * 只钉两件事：
 * 1. **字节不变量**：有了 TagNode 之后，树重放仍与原文逐字节相同；
 * 2. **结构不变量**：配对、自闭合、跨 PHP 片段、未闭合、多余闭标签都有确定行为。
 *
 * 标签语义（translate/emit 什么）不在本测试范围——那是 Resolve/Lower 的事。
 *
 * @author Aiweline
 */

namespace Weline\Framework\Test\Unit\View\Compiler;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Compiler\Lexer\Lexer;
use Weline\Framework\View\Compiler\Parser\Document;
use Weline\Framework\View\Compiler\Parser\InlineNode;
use Weline\Framework\View\Compiler\Parser\Node;
use Weline\Framework\View\Compiler\Parser\Parser;
use Weline\Framework\View\Compiler\Parser\PhpNode;
use Weline\Framework\View\Compiler\Parser\TagNode;
use Weline\Framework\View\Compiler\Parser\TextNode;
use Weline\Framework\View\Compiler\Tag\CompiledTagNameMatcher;
use Weline\Framework\View\Compiler\Tag\StaticTagNameMatcher;

final class ParserTest extends TestCase
{
    private function parser(array $names): Parser
    {
        return new Parser(new Lexer(), new StaticTagNameMatcher($names));
    }

    private function assertRoundTrip(Document $document, string $source): void
    {
        self::assertTrue($document->isRoundTrip(), '树重放必须与原文逐字节相同');
        self::assertSame($source, $document->replay());
    }

    public function testPairedTagBecomesTagNode(): void
    {
        $source = '<w:block>hello</w:block>';
        $document = $this->parser(['w:block'])->parse($source);

        $this->assertRoundTrip($document, $source);
        self::assertCount(1, $document->nodes());
        $node = $document->nodes()[0];
        self::assertInstanceOf(TagNode::class, $node);
        self::assertSame('w:block', $node->name());
        self::assertTrue($node->isPaired());
        self::assertFalse($node->isSelfClosing());
        self::assertCount(1, $node->children());
        self::assertInstanceOf(TextNode::class, $node->children()[0]);
    }

    public function testSelfClosingTagAndAttributes(): void
    {
        $source = '<w:slot name="header" size=\'lg\' data-on />';
        $document = $this->parser(['w:slot'])->parse($source);

        $this->assertRoundTrip($document, $source);
        /** @var TagNode $node */
        $node = $document->nodes()[0];
        self::assertTrue($node->isSelfClosing());
        self::assertSame(
            ['name' => 'header', 'size' => 'lg', 'data-on' => true],
            $node->attributes(),
        );
    }

    public function testAttributeValueMayContainGreaterThan(): void
    {
        $source = '<w:block data-tip="a>b">x</w:block>';
        $document = $this->parser(['w:block'])->parse($source);

        $this->assertRoundTrip($document, $source);
        /** @var TagNode $node */
        $node = $document->nodes()[0];
        self::assertSame('a>b', $node->attributes()['data-tip']);
    }

    public function testPlainHtmlStaysText(): void
    {
        $source = '<div class="x"><span>hi</span></div>';
        $document = $this->parser(['w:block'])->parse($source);

        $this->assertRoundTrip($document, $source);
        self::assertCount(1, $document->nodes());
        self::assertInstanceOf(TextNode::class, $document->nodes()[0]);
    }

    public function testTagWrappingPhpKeepsStructure(): void
    {
        $source = '<w:block><?php echo 1; ?></w:block>';
        $document = $this->parser(['w:block'])->parse($source);

        $this->assertRoundTrip($document, $source);
        /** @var TagNode $node */
        $node = $document->nodes()[0];
        self::assertInstanceOf(TagNode::class, $node);
        self::assertCount(1, $node->children());
        self::assertInstanceOf(PhpNode::class, $node->children()[0]);
    }

    public function testUnclosedTagBecomesSingleTagWithChildren(): void
    {
        $source = 'a<w:block>b';
        $document = $this->parser(['w:block'])->parse($source);

        $this->assertRoundTrip($document, $source);
        self::assertCount(2, $document->nodes());
        /** @var TagNode $node */
        $node = $document->nodes()[1];
        self::assertInstanceOf(TagNode::class, $node);
        self::assertFalse($node->isPaired());
        // 未闭合标签的 raw 是从开标签到文件尾的整段切片（否则重放会丢字节）。
        self::assertSame('<w:block>b', $node->raw());
        self::assertInstanceOf(TextNode::class, $node->children()[0]);
    }

    public function testStrayClosingTagStaysText(): void
    {
        $source = 'x</w:block>';
        $document = $this->parser(['w:block'])->parse($source);

        $this->assertRoundTrip($document, $source);
        self::assertCount(2, $document->nodes());
        self::assertInstanceOf(TextNode::class, $document->nodes()[1]);
        self::assertSame('</w:block>', $document->nodes()[1]->raw());
    }

    public function testMismatchedInnerTagIsClosedBeforeOuter(): void
    {
        $source = '<w:block><w:slot>x</w:block>';
        $document = $this->parser(['w:block', 'w:slot'])->parse($source);

        $this->assertRoundTrip($document, $source);
        /** @var TagNode $outer */
        $outer = $document->nodes()[0];
        self::assertSame('w:block', $outer->name());
        self::assertTrue($outer->isPaired());

        /** @var TagNode $inner */
        $inner = $outer->children()[0];
        self::assertSame('w:slot', $inner->name());
        self::assertFalse($inner->isPaired(), '未闭合的内层标签按单标签收口');
        self::assertSame('<w:slot>x', $inner->raw());
    }

    /**
     * 内联形态：自包含、无配对，body 原文保留。
     */
    public function testInlineTagsAreParsed(): void
    {
        $source = '<p aria-label="@lang{面包屑}"><img src="@static(a/b.png)"></p>';
        $document = $this->parser(['lang', 'static'])->parse($source);

        $this->assertRoundTrip($document, $source);

        $inline = [];
        $walk = static function (array $nodes) use (&$walk, &$inline): void {
            foreach ($nodes as $node) {
                if ($node instanceof InlineNode) {
                    $inline[] = $node;
                }
                if ($node instanceof TagNode) {
                    $walk($node->children());
                }
            }
        };
        $walk($document->nodes());

        self::assertCount(2, $inline);
        self::assertSame('lang', $inline[0]->name());
        self::assertSame('{', $inline[0]->delimiter());
        self::assertSame('面包屑', $inline[0]->bodyRaw());
        self::assertSame('static', $inline[1]->name());
        self::assertSame('(', $inline[1]->delimiter());
        self::assertSame('a/b.png', $inline[1]->bodyRaw());
    }

    public function testInlineBodyRespectsQuotesAndNesting(): void
    {
        $source = "@if{a=='}'?'x':'y'}@var(foo(1, 2))";
        $document = $this->parser(['if', 'var'])->parse($source);

        $this->assertRoundTrip($document, $source);
        self::assertCount(2, $document->nodes());
        /** @var InlineNode $first */
        $first = $document->nodes()[0];
        self::assertSame("a=='}'?'x':'y'", $first->bodyRaw());
        /** @var InlineNode $second */
        $second = $document->nodes()[1];
        self::assertSame('foo(1, 2)', $second->bodyRaw());
    }

    public function testInlineLookalikesStayText(): void
    {
        $sources = [
            '@media(min-width: 600px){color:red}',
            'mail me: user@example.com',
            '@lang{未闭合',
        ];
        foreach ($sources as $source) {
            $document = $this->parser(['lang'])->parse($source);
            $this->assertRoundTrip($document, $source);
            self::assertCount(1, $document->nodes(), '非注册内联标签必须原样留在文本里：' . $source);
            self::assertInstanceOf(TextNode::class, $document->nodes()[0]);
        }
    }

    /**
     * 全量语料护栏：用真实标签名单（`generated/taglibs.php` + 内置补齐）解析全部模板。
     *
     * 只要有一个模板重放不上，或解析抛异常，就说明标签层还不能接 Resolve。
     */
    public function testCorpusParsesAndReplaysWithRealTagNames(): void
    {
        if (!\defined('BP')) {
            self::markTestSkipped('BP 未定义，无法定位语料根');
        }

        // 内置标签不在编译索引里：元素形态与内联形态必须分开注入，
        // 否则 `backend-url` 这类只支持 `@backend-url{...}` 的名字会把元素形态也吃掉。
        $matcher = new CompiledTagNameMatcher(
            BP . 'generated/taglibs.php',
            [
                'lang', 'trans', 'hook', 'else', 'if', 'elseif', 'foreach', 'while',
                'switch', 'case', 'default', 'block', 'slot', 'include', 'template',
                'widget', 'form', 'field', 'url', 'static', 'csrf', 'icon',
            ],
            ['backend-url', 'frontend-url', 'admin-url', 'var', 'api'],
        );
        self::assertTrue($matcher->matchesElement('acl'), '编译索引里应能找到声明了元素形态的模块标签');
        self::assertFalse($matcher->matchesElement('div'));
        // Weline_Meta 只支持 @meta{...} 内联形态：HTML 的 <meta> 不能被当成模板标签。
        self::assertFalse($matcher->matchesElement('meta'));
        self::assertTrue($matcher->matchesInline('meta'));

        $parser = new Parser(new Lexer(), $matcher);
        $files = 0;
        $tags = 0;
        $bad = [];

        foreach ([BP . 'app/code', BP . 'app/design'] as $root) {
            if (!\is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                    static function (\SplFileInfo $file): bool {
                        if ($file->isDir()) {
                            return !\in_array($file->getFilename(), ['vendor', 'node_modules', 'generated'], true);
                        }

                        return \str_ends_with($file->getFilename(), '.phtml');
                    }
                )
            );

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                $path = $file->getPathname();
                if (\str_contains($path, '/view/tpl/')) {
                    continue;
                }
                $source = @\file_get_contents($path);
                if (!\is_string($source)) {
                    continue;
                }
                $files++;
                try {
                    $document = $parser->parse($source);
                } catch (\Throwable $e) {
                    if (\count($bad) < 5) {
                        $bad[] = $path . ' :: ' . $e::class . ': ' . $e->getMessage();
                    }
                    continue;
                }
                if (!$document->isRoundTrip() && \count($bad) < 5) {
                    $bad[] = $path . ' :: 重放不一致';
                }
                $tags += $this->countTags($document->nodes());
            }
        }

        self::assertGreaterThan(1000, $files);
        self::assertSame([], $bad, '以下模板在标签层解析失败');
        self::assertGreaterThan(1000, $tags, '真实语料里应解析出大量标签节点');
    }

    /**
     * @param list<Node> $nodes
     */
    private function countTags(array $nodes): int
    {
        $count = 0;
        foreach ($nodes as $node) {
            if ($node instanceof TagNode) {
                $count++;
                $count += $this->countTags($node->children());
            }
        }

        return $count;
    }
}
