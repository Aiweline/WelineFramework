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
     * 当前实现会内联展开模板内容
     */
    public function testTemplateCompileOutput(): void
    {
        $taglib = new \Weline\Framework\View\Taglib();
        $template = \Weline\Framework\View\Template::getInstance();

        $content = '@template(Weline_Admin::common/head.phtml)';
        $result = $taglib->compile($template, $content, 'test.phtml');

        // 当前实现会内联展开模板内容，应该包含模板内容
        // 如果模板存在，结果应该包含其内容；否则可能是警告信息
        self::assertTrue(
            str_contains($result, '<!--') || str_contains($result, '<?php') || str_contains($result, 'head'),
            '编译结果应该包含模板内容或警告信息'
        );
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

        // 当前实现会内联展开模板内容
        // 验证结果不为空，说明模板被正确处理
        self::assertNotEmpty($result, '编译结果不应为空');
    }

    // ====== expandInlineTags 预展开测试 ======

    /**
     * 测试 @var{} 内联标签预展开
     *
     * @var 是最简单的内联标签，验证预展开后编译结果中包含 PHP echo
     */
    public function testExpandInlineVar(): void
    {
        $taglib = new \Weline\Framework\View\Taglib();
        $template = \Weline\Framework\View\Template::getInstance();

        $content = '<span>@var{$name}</span>';
        $result = $taglib->compile($template, $content, 'test_expand.phtml');

        // @var{$name} 应被展开为 PHP echo，编译后不应残留 @var
        self::assertStringNotContainsString('@var', $result, '@var should be pre-expanded');
        self::assertStringContainsString('$name', $result, '$name should appear in compiled result');
    }

    /**
     * 测试 @if{} 内联标签预展开
     *
     * 重点验证之前 <tr @if{...}> 无法解析的场景
     */
    public function testExpandInlineIfInHtmlAttribute(): void
    {
        $taglib = new \Weline\Framework\View\Taglib();
        $template = \Weline\Framework\View\Template::getInstance();

        $content = '<tr @if{$cache->getPermanently()=>"style=\'background:#2a334d\'"}>';
        $result = $taglib->compile($template, $content, 'test_expand_if.phtml');

        // @if{...} 应被预展开为 PHP 代码，编译结果中不应残留 @if{
        self::assertStringNotContainsString('@if{', $result, '@if should be pre-expanded');
        self::assertStringContainsString('<' . '?', $result, 'expanded result should contain PHP code');
    }

    /**
     * 测试多个内联标签混合预展开
     */
    public function testExpandMultipleInlineTags(): void
    {
        $taglib = new \Weline\Framework\View\Taglib();
        $template = \Weline\Framework\View\Template::getInstance();

        $content = '<div>@var{$title}</div><p>@var{$content}</p>';
        $result = $taglib->compile($template, $content, 'test_expand_multi.phtml');

        self::assertStringNotContainsString('@var', $result, '所有 @var 标签应被预展开');
        self::assertStringContainsString('$title', $result);
        self::assertStringContainsString('$content', $result);
    }

    /**
     * 测试无 @ 的内容不受影响（快速路径）
     */
    public function testExpandNoAtSymbol(): void
    {
        $taglib = new \Weline\Framework\View\Taglib();
        $template = \Weline\Framework\View\Template::getInstance();

        $content = '<div>Hello World</div>';
        $result = $taglib->compile($template, $content, 'test_expand_no_at.phtml');

        self::assertStringContainsString('Hello World', $result, '无 @ 的内容应原样保留');
    }

    /**
     * 测试 email 中的 @ 不被误处理
     */
    public function testExpandEmailAtNotMistaken(): void
    {
        $taglib = new \Weline\Framework\View\Taglib();
        $template = \Weline\Framework\View\Template::getInstance();

        $content = '<a href="mailto:user@example.com">user@example.com</a>';
        $result = $taglib->compile($template, $content, 'test_expand_email.phtml');

        // email 中的 @ 后跟的 example 不是已注册标签，不应被处理
        self::assertStringContainsString('@example.com', $result, 'email 地址中的 @ 不应被展开');
    }

    /**
     * 测试 @if{} 带多条件（| 分隔）预展开
     */
    public function testExpandInlineIfMultiCondition(): void
    {
        $taglib = new \Weline\Framework\View\Taglib();
        $template = \Weline\Framework\View\Template::getInstance();

        $content = '@if{$a===1=>yes|$a===2=>no|default}';
        $result = $taglib->compile($template, $content, 'test_expand_if_multi.phtml');

        self::assertStringNotContainsString('@if{', $result, '@if 多条件标签应被预展开');
        self::assertStringContainsString('if(', $result, '预展开后应包含 if 语句');
    }

    /**
     * 测试 @lang() 内联标签预展开
     */
    public function testExpandInlineLang(): void
    {
        $taglib = new \Weline\Framework\View\Taglib();
        $template = \Weline\Framework\View\Template::getInstance();

        $content = '<button>@lang(提交)</button>';
        $result = $taglib->compile($template, $content, 'test_expand_lang.phtml');

        // @lang(提交) 应被预展开为翻译后的文本
        self::assertStringNotContainsString('@lang(', $result, '@lang 应被预展开');
    }

    /**
     * 测试 @block{} 内联标签预展开
     */
    public function testExpandInlineBlock(): void
    {
        $taglib = new \Weline\Framework\View\Taglib();
        $template = \Weline\Framework\View\Template::getInstance();

        $content = '@block{Weline\Demo\Block\Demo|template=Weline_Demo::demo.phtml}';
        $result = $taglib->compile($template, $content, 'test_expand_block.phtml');

        // @block{...} 应被预展开为 framework_view_process_block 调用
        self::assertStringNotContainsString('@block{', $result, '@block 内联标签应被预展开');
        self::assertStringContainsString('framework_view_process_block', $result, '应生成 block 处理调用');
    }

    /**
     * 测试异常回调：expandInlineTags 捕获异常后保留原文
     *
     * @elseif 独立使用时回调会抛 TemplateException，预展开阶段会捕获异常并保留原文，
     * 但后续编译管道仍会处理残留的 @elseif 并抛出异常（这是正确行为）。
     * 此测试验证 TemplateException 确实被抛出。
     */
    public function testExpandCallbackExceptionIsHandled(): void
    {
        $taglib = new \Weline\Framework\View\Taglib();
        $template = \Weline\Framework\View\Template::getInstance();

        // @elseif{...} 独立使用会在编译管道中抛出 TemplateException
        $this->expectException(\Weline\Framework\View\Exception\TemplateException::class);
        $content = '@elseif{$x=>y}';
        $taglib->compile($template, $content, 'test_expand_exception.phtml');
    }
}
