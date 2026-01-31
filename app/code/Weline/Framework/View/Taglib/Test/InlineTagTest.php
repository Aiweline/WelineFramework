<?php
declare(strict_types=1);

/**
 * Weline Framework
 * 
 * @DESC         | 内联标签 @template(...) 单元测试
 * @Author       | Weline Framework
 * @Package      | Weline\Framework\View\Taglib\Test
 */

namespace Weline\Framework\View\Taglib\Test;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Taglib\Parser\Tokenizer;
use Weline\Framework\View\Taglib\Parser\AstBuilder;
use Weline\Framework\View\Taglib\Parser\PhpExtractor;
use Weline\Framework\View\Taglib\Parser\TokenType;
use Weline\Framework\View\Taglib\Compiler\CompilePipeline;
use Weline\Framework\View\Taglib\Compiler\NodeCompiler;
use Weline\Framework\View\Taglib\Generator\CodeGenerator;
use Weline\Framework\View\Taglib\Ast\{TagNode, TextNode, NodePool};

class InlineTagTest extends TestCase
{
    private Tokenizer $tokenizer;
    private AstBuilder $builder;

    protected function setUp(): void
    {
        $this->tokenizer = new Tokenizer();
        $this->builder = new AstBuilder();
        
        // 设置框架标签，包括 template
        $this->tokenizer->setFrameworkTags(['template', 'include', 'if', 'foreach', 'block']);
        
        NodePool::reset();
    }

    protected function tearDown(): void
    {
        NodePool::reset();
    }

    /**
     * 测试基本的 @template() 标签
     */
    public function testTemplateTagBasic(): void
    {
        $content = '@template(Weline_Admin::common/head.phtml)';
        $tokens = $this->tokenizer->tokenizeToArray($content);

        self::assertCount(1, $tokens);
        self::assertEquals(TokenType::InlineTag, $tokens[0]->type);
        self::assertEquals('template', $tokens[0]->value);
        self::assertEquals('Weline_Admin::common/head.phtml', $tokens[0]->getMeta('content'));
    }

    /**
     * 测试多个 @template() 标签
     */
    public function testMultipleTemplateTags(): void
    {
        $content = '@template(Weline_Admin::common/head.phtml) @template(Weline_Admin::common/page/loading.phtml)
@template(Weline_Admin::common/left-sidebar.phtml)';
        
        $tokens = $this->tokenizer->tokenizeToArray($content);

        // 应该有 3 个 InlineTag 和 2 个空格/换行的 Text
        $inlineTags = array_filter($tokens, fn($t) => $t->type === TokenType::InlineTag);
        self::assertCount(3, $inlineTags);
        
        $tagContents = array_map(fn($t) => $t->getMeta('content'), $inlineTags);
        self::assertContains('Weline_Admin::common/head.phtml', $tagContents);
        self::assertContains('Weline_Admin::common/page/loading.phtml', $tagContents);
        self::assertContains('Weline_Admin::common/left-sidebar.phtml', $tagContents);
    }

    /**
     * 测试 @template() 与普通 HTML 混合
     */
    public function testTemplateMixedWithHtml(): void
    {
        $content = '<div>@template(header.phtml)</div>';
        $tokens = $this->tokenizer->tokenizeToArray($content);

        $types = array_map(fn($t) => $t->type, $tokens);
        
        self::assertContains(TokenType::Text, $types);
        self::assertContains(TokenType::InlineTag, $types);
    }

    /**
     * 测试带有嵌套括号的内容
     */
    public function testTemplateWithNestedParentheses(): void
    {
        $content = '@template(path/to/file(1).phtml)';
        $tokens = $this->tokenizer->tokenizeToArray($content);

        self::assertCount(1, $tokens);
        self::assertEquals(TokenType::InlineTag, $tokens[0]->type);
        self::assertEquals('path/to/file(1).phtml', $tokens[0]->getMeta('content'));
    }

    /**
     * 测试大括号格式的内联标签
     */
    public function testCurlyBraceInlineTag(): void
    {
        $content = '@template{Weline_Admin::common/head.phtml}';
        $tokens = $this->tokenizer->tokenizeToArray($content);

        self::assertCount(1, $tokens);
        self::assertEquals(TokenType::InlineTag, $tokens[0]->type);
        self::assertEquals('template', $tokens[0]->value);
        self::assertEquals('Weline_Admin::common/head.phtml', $tokens[0]->getMeta('content'));
    }

    /**
     * 测试带有嵌套大括号的内容
     */
    public function testCurlyBraceWithNestedBraces(): void
    {
        $content = '@if{$a > {1+1}}';
        $tokens = $this->tokenizer->tokenizeToArray($content);

        self::assertCount(1, $tokens);
        self::assertEquals(TokenType::InlineTag, $tokens[0]->type);
        self::assertEquals('$a > {1+1}', $tokens[0]->getMeta('content'));
    }

    /**
     * 测试 @if() 内联标签
     */
    public function testIfInlineTag(): void
    {
        $content = '@if($show)';
        $tokens = $this->tokenizer->tokenizeToArray($content);

        self::assertCount(1, $tokens);
        self::assertEquals(TokenType::InlineTag, $tokens[0]->type);
        self::assertEquals('if', $tokens[0]->value);
        self::assertEquals('$show', $tokens[0]->getMeta('content'));
    }

    /**
     * 测试 @foreach() 内联标签
     */
    public function testForeachInlineTag(): void
    {
        $content = '@foreach($items as $item)';
        $tokens = $this->tokenizer->tokenizeToArray($content);

        self::assertCount(1, $tokens);
        self::assertEquals(TokenType::InlineTag, $tokens[0]->type);
        self::assertEquals('foreach', $tokens[0]->value);
        self::assertEquals('$items as $item', $tokens[0]->getMeta('content'));
    }

    /**
     * 测试 AST 构建 - @template() 转换为 TagNode
     */
    public function testAstBuildTemplateTag(): void
    {
        $content = '@template(Weline_Admin::common/head.phtml)';
        $tokens = $this->tokenizer->tokenize($content);
        $ast = $this->builder->build($tokens);

        self::assertCount(1, $ast->children);
        self::assertInstanceOf(TagNode::class, $ast->children[0]);
        
        $tagNode = $ast->children[0];
        self::assertEquals('template', $tagNode->name);
        self::assertTrue($tagNode->selfClosing);
        self::assertCount(1, $tagNode->attributes);
        self::assertEquals('value', $tagNode->attributes[0]->name);
        self::assertEquals('Weline_Admin::common/head.phtml', $tagNode->attributes[0]->staticValue);
    }

    /**
     * 测试 AST 构建 - 多个 @template()
     */
    public function testAstBuildMultipleTemplateTags(): void
    {
        $content = '@template(Weline_Admin::common/head.phtml) @template(Weline_Admin::common/page/loading.phtml)
@template(Weline_Admin::common/left-sidebar.phtml)';
        
        $tokens = $this->tokenizer->tokenize($content);
        $ast = $this->builder->build($tokens);

        // 过滤出 TagNode
        $tagNodes = array_filter($ast->children, fn($n) => $n instanceof TagNode);
        self::assertCount(3, $tagNodes);
        
        foreach ($tagNodes as $node) {
            self::assertEquals('template', $node->name);
            self::assertTrue($node->selfClosing);
        }
    }

    /**
     * 测试非框架标签的 @ 符号保持为文本
     */
    public function testNonFrameworkAtSymbol(): void
    {
        $content = 'email@example.com';
        $tokens = $this->tokenizer->tokenizeToArray($content);

        // 整个内容应该作为文本
        $allText = implode('', array_map(fn($t) => $t->value, 
            array_filter($tokens, fn($t) => $t->type === TokenType::Text)
        ));
        
        // @ 没有跟随有效的标签名和括号，所以应该作为文本处理
        self::assertStringContainsString('@', $allText);
    }

    /**
     * 测试带有属性的内联标签
     */
    public function testInlineTagWithAttributes(): void
    {
        $content = '@block(template="sidebar.phtml" class="container")';
        $tokens = $this->tokenizer->tokenize($content);
        $ast = $this->builder->build($tokens);

        self::assertCount(1, $ast->children);
        $tagNode = $ast->children[0];
        self::assertInstanceOf(TagNode::class, $tagNode);
        self::assertEquals('block', $tagNode->name);
        
        // 应该解析出属性
        self::assertGreaterThanOrEqual(1, count($tagNode->attributes));
    }

    /**
     * 测试原始内容保留
     */
    public function testRawContentPreserved(): void
    {
        $content = '@template(Weline_Admin::common/head.phtml)';
        $tokens = $this->tokenizer->tokenize($content);
        $ast = $this->builder->build($tokens);

        $tagNode = $ast->children[0];
        self::assertEquals('Weline_Admin::common/head.phtml', $tagNode->rawContent);
    }

    /**
     * 测试行号正确
     */
    public function testLineNumbers(): void
    {
        $content = "line1\n@template(test.phtml)\nline3";
        $tokens = $this->tokenizer->tokenizeToArray($content);

        $templateToken = null;
        foreach ($tokens as $token) {
            if ($token->type === TokenType::InlineTag) {
                $templateToken = $token;
                break;
            }
        }

        self::assertNotNull($templateToken);
        self::assertEquals(2, $templateToken->line);
    }

    /**
     * 测试 @template() 完整编译流程
     * 
     * 使用完整的 Taglib 编译流程，包括已注册的标签回调
     */
    public function testTemplateCompileOutput(): void
    {
        $taglib = new \Weline\Framework\View\Taglib();
        $template = \Weline\Framework\View\Template::getInstance();

        $content = '@template(Weline_Admin::common/head.phtml)';
        $result = $taglib->compile($template, $content, 'test.phtml');

        // 新实现使用 fetchTagHtml
        self::assertStringContainsString('fetchTagHtml', $result);
        self::assertStringContainsString('Weline_Admin::common/head.phtml', $result);
    }

    /**
     * 测试多个 @template() 完整编译
     */
    public function testMultipleTemplateCompileOutput(): void
    {
        $taglib = new \Weline\Framework\View\Taglib();
        $template = \Weline\Framework\View\Template::getInstance();

        $content = '@template(Weline_Admin::common/head.phtml) @template(Weline_Admin::common/page/loading.phtml)
@template(Weline_Admin::common/left-sidebar.phtml)';
        
        $result = $taglib->compile($template, $content, 'test.phtml');

        self::assertStringContainsString('head.phtml', $result);
        self::assertStringContainsString('loading.phtml', $result);
        self::assertStringContainsString('left-sidebar.phtml', $result);
        
        // 新实现使用 fetchTagHtml，应该有 3 个调用
        $count = substr_count($result, 'fetchTagHtml');
        self::assertEquals(3, $count);
    }
}
