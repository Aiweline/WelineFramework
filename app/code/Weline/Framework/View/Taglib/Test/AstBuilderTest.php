<?php
declare(strict_types=1);

/**
 * Weline Framework
 * 
 * @DESC         | AstBuilder 单元测试
 * @Author       | Weline Framework
 * @Package      | Weline\Framework\View\Taglib\Test
 */

namespace Weline\Framework\View\Taglib\Test;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Taglib\Parser\AstBuilder;
use Weline\Framework\View\Taglib\Parser\Tokenizer;
use Weline\Framework\View\Taglib\Parser\PhpExtractor;
use Weline\Framework\View\Taglib\Ast\{
    ProgramNode,
    TextNode,
    TagNode,
    PhpPlaceholder,
    NodePool
};

class AstBuilderTest extends TestCase
{
    private AstBuilder $builder;
    private Tokenizer $tokenizer;
    private PhpExtractor $phpExtractor;

    protected function setUp(): void
    {
        $this->builder = new AstBuilder();
        $this->tokenizer = new Tokenizer();
        $this->phpExtractor = new PhpExtractor();
        
        $this->tokenizer->setFrameworkTags(['block', 'lang', 'if', 'foreach']);
        $this->builder->setPhpExtractor($this->phpExtractor);
        
        NodePool::reset();
    }

    protected function tearDown(): void
    {
        NodePool::reset();
        $this->phpExtractor->reset();
    }

    public function testBuildTextNode(): void
    {
        $content = 'Hello World';
        $tokens = $this->tokenizer->tokenize($content);
        $ast = $this->builder->build($tokens);

        self::assertInstanceOf(ProgramNode::class, $ast);
        self::assertCount(1, $ast->children);
        self::assertInstanceOf(TextNode::class, $ast->children[0]);
        self::assertEquals('Hello World', $ast->children[0]->value);
    }

    public function testBuildTagNode(): void
    {
        $content = '<block template="test.phtml">Content</block>';
        $tokens = $this->tokenizer->tokenize($content);
        $ast = $this->builder->build($tokens);

        self::assertCount(1, $ast->children);
        self::assertInstanceOf(TagNode::class, $ast->children[0]);
        
        $tagNode = $ast->children[0];
        self::assertEquals('block', $tagNode->name);
        self::assertFalse($tagNode->selfClosing);
        self::assertCount(1, $tagNode->children);
    }

    public function testBuildSelfClosingTag(): void
    {
        $content = '<block template="test.phtml"/>';
        $tokens = $this->tokenizer->tokenize($content);
        $ast = $this->builder->build($tokens);

        self::assertCount(1, $ast->children);
        self::assertInstanceOf(TagNode::class, $ast->children[0]);
        
        $tagNode = $ast->children[0];
        self::assertTrue($tagNode->selfClosing);
        self::assertEmpty($tagNode->children);
    }

    public function testBuildNestedTags(): void
    {
        $content = '<block><lang>Hello</lang></block>';
        $tokens = $this->tokenizer->tokenize($content);
        $ast = $this->builder->build($tokens);

        self::assertCount(1, $ast->children);
        
        $blockNode = $ast->children[0];
        self::assertEquals('block', $blockNode->name);
        self::assertCount(1, $blockNode->children);
        
        $langNode = $blockNode->children[0];
        self::assertInstanceOf(TagNode::class, $langNode);
        self::assertEquals('lang', $langNode->name);
    }

    public function testBuildWithPlaceholder(): void
    {
        $original = '<div>' . '<' . '?= $name ?' . '>' . '</div>';
        $extracted = $this->phpExtractor->extract($original);
        
        $tokens = $this->tokenizer->tokenize($extracted);
        $ast = $this->builder->build($tokens);

        // 找到占位符节点
        $hasPlaceholder = false;
        foreach ($ast->children as $child) {
            if ($child instanceof PhpPlaceholder) {
                $hasPlaceholder = true;
                self::assertEquals('$name', $child->expression);
            }
        }
        self::assertTrue($hasPlaceholder);
    }

    public function testBuildWithAttributes(): void
    {
        $content = '<block template="test.phtml" class="container"/>';
        $tokens = $this->tokenizer->tokenize($content);
        $ast = $this->builder->build($tokens);

        $tagNode = $ast->children[0];
        self::assertCount(2, $tagNode->attributes);
        
        self::assertEquals('template', $tagNode->attributes[0]->name);
        self::assertEquals('test.phtml', $tagNode->attributes[0]->staticValue);
        
        self::assertEquals('class', $tagNode->attributes[1]->name);
        self::assertEquals('container', $tagNode->attributes[1]->staticValue);
    }

    public function testIsDynamicProperty(): void
    {
        // 静态内容
        $content = '<block template="test.phtml">Static</block>';
        $tokens = $this->tokenizer->tokenize($content);
        $ast = $this->builder->build($tokens);
        
        self::assertFalse($ast->isDynamic);
    }

    public function testFileName(): void
    {
        $tokens = $this->tokenizer->tokenize('Hello');
        $ast = $this->builder->build($tokens, 'test.phtml');

        self::assertEquals('test.phtml', $ast->fileName);
    }
}
