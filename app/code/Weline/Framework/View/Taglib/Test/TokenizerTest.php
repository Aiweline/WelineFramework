<?php
declare(strict_types=1);

/**
 * Weline Framework
 * 
 * @DESC         | Tokenizer 单元测试
 * @Author       | Weline Framework
 * @Package      | Weline\Framework\View\Taglib\Test
 */

namespace Weline\Framework\View\Taglib\Test;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Taglib\Parser\Tokenizer;
use Weline\Framework\View\Taglib\Parser\TokenType;

class TokenizerTest extends TestCase
{
    private Tokenizer $tokenizer;

    protected function setUp(): void
    {
        $this->tokenizer = new Tokenizer();
        $this->tokenizer->setFrameworkTags(['block', 'lang', 'if', 'foreach', 'template']);
    }

    public function testTokenizeText(): void
    {
        $content = 'Hello World';
        $tokens = $this->tokenizer->tokenizeToArray($content);

        self::assertCount(1, $tokens);
        self::assertEquals(TokenType::Text, $tokens[0]->type);
        self::assertEquals('Hello World', $tokens[0]->value);
    }

    public function testTokenizeOpenTag(): void
    {
        $content = '<block template="test.phtml">';
        $tokens = $this->tokenizer->tokenizeToArray($content);

        self::assertCount(1, $tokens);
        self::assertEquals(TokenType::OpenTag, $tokens[0]->type);
        self::assertEquals('block', $tokens[0]->value);
    }

    public function testTokenizeSelfCloseTag(): void
    {
        $content = '<block template="test.phtml"/>';
        $tokens = $this->tokenizer->tokenizeToArray($content);

        self::assertCount(1, $tokens);
        self::assertEquals(TokenType::SelfCloseTag, $tokens[0]->type);
        self::assertEquals('block', $tokens[0]->value);
    }

    public function testTokenizeCloseTag(): void
    {
        $content = '</block>';
        $tokens = $this->tokenizer->tokenizeToArray($content);

        self::assertCount(1, $tokens);
        self::assertEquals(TokenType::CloseTag, $tokens[0]->type);
        self::assertEquals('block', $tokens[0]->value);
    }

    public function testTokenizeMixedContent(): void
    {
        $content = '<block>Hello</block>';
        $tokens = $this->tokenizer->tokenizeToArray($content);

        self::assertCount(3, $tokens);
        self::assertEquals(TokenType::OpenTag, $tokens[0]->type);
        self::assertEquals(TokenType::Text, $tokens[1]->type);
        self::assertEquals(TokenType::CloseTag, $tokens[2]->type);
    }

    public function testTokenizePlaceholder(): void
    {
        $content = '<div>__PHP_0__</div>';
        $tokens = $this->tokenizer->tokenizeToArray($content);

        // <div> 是普通 HTML，不是框架标签，所以作为文本
        // __PHP_0__ 是占位符
        $hasPlaceholder = false;
        foreach ($tokens as $token) {
            if ($token->type === TokenType::Placeholder) {
                $hasPlaceholder = true;
                self::assertEquals('__PHP_0__', $token->value);
            }
        }
        self::assertTrue($hasPlaceholder);
    }

    public function testTokenizeAttributes(): void
    {
        $content = '<block template="test.phtml" class="container">';
        $tokens = $this->tokenizer->tokenizeToArray($content);

        self::assertCount(1, $tokens);
        $rawAttrs = $tokens[0]->getMeta('rawAttrs');
        self::assertStringContainsString('template=', $rawAttrs);
        self::assertStringContainsString('class=', $rawAttrs);
    }

    public function testNonFrameworkTagAsText(): void
    {
        $content = '<div>Hello</div>';
        $tokens = $this->tokenizer->tokenizeToArray($content);

        // 所有 HTML 标签应该作为文本处理
        foreach ($tokens as $token) {
            self::assertEquals(TokenType::Text, $token->type);
        }
    }

    public function testNamespacedTag(): void
    {
        $this->tokenizer->setFrameworkTags(['w']);
        $content = '<w:seo:account:select id="test"/>';
        $tokens = $this->tokenizer->tokenizeToArray($content);

        self::assertCount(1, $tokens);
        self::assertEquals(TokenType::SelfCloseTag, $tokens[0]->type);
        self::assertEquals('w:seo:account:select', $tokens[0]->value);
    }

    /**
     * 测试 @template() 内联标签
     */
    public function testInlineTemplateTag(): void
    {
        $content = '@template(Weline_Admin::common/head.phtml)';
        $tokens = $this->tokenizer->tokenizeToArray($content);

        self::assertCount(1, $tokens);
        self::assertEquals(TokenType::InlineTag, $tokens[0]->type);
        self::assertEquals('template', $tokens[0]->value);
        self::assertEquals('Weline_Admin::common/head.phtml', $tokens[0]->getMeta('content'));
    }

    /**
     * 测试多个 @template() 内联标签
     */
    public function testMultipleInlineTemplateTags(): void
    {
        $content = '@template(Weline_Admin::common/head.phtml) @template(Weline_Admin::common/page/loading.phtml)
@template(Weline_Admin::common/left-sidebar.phtml)';
        
        $tokens = $this->tokenizer->tokenizeToArray($content);

        $inlineTags = array_filter($tokens, fn($t) => $t->type === TokenType::InlineTag);
        self::assertCount(3, $inlineTags);
        
        $tagContents = array_values(array_map(fn($t) => $t->getMeta('content'), $inlineTags));
        self::assertEquals('Weline_Admin::common/head.phtml', $tagContents[0]);
        self::assertEquals('Weline_Admin::common/page/loading.phtml', $tagContents[1]);
        self::assertEquals('Weline_Admin::common/left-sidebar.phtml', $tagContents[2]);
    }

    /**
     * 测试 @template() 与 HTML 混合
     */
    public function testInlineTagMixedWithHtml(): void
    {
        $content = '<div>@template(header.phtml)</div>';
        $tokens = $this->tokenizer->tokenizeToArray($content);

        $types = array_map(fn($t) => $t->type, $tokens);
        
        self::assertContains(TokenType::Text, $types);
        self::assertContains(TokenType::InlineTag, $types);
    }
}
