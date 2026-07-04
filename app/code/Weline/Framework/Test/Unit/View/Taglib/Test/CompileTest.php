<?php
declare(strict_types=1);

/**
 * Weline Framework
 * 
 * @DESC         | Taglib 编译流程单元测试
 * @Author       | Weline Framework
 * @Package      | Weline\Framework\View\Taglib\Test
 */

namespace Weline\Framework\View\Taglib\Test;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Taglib;
use Weline\Framework\View\Template;
use Weline\Framework\Manager\ObjectManager;

class CompileTest extends TestCase
{
    private Taglib $taglib;
    private Template $template;

    protected function setUp(): void
    {
        $this->taglib = ObjectManager::getInstance(Taglib::class);
        $this->template = ObjectManager::getInstance(Template::class);
    }

    /**
     * 测试 block 成对标签编译
     */
    public function testBlockPairedTagCompile(): void
    {
        $content = '<block>Weline\Backend\Block\Header\Base</block>';
        $fileName = 'test.phtml';
        
        // 编译应该不抛出异常
        $result = $this->taglib->compile($this->template, $content, $fileName);
        
        // 结果应该包含 framework_view_process_block 调用
        self::assertStringContainsString('framework_view_process_block', $result, 
            'block 成对标签应该生成 framework_view_process_block 调用');
    }

    /**
     * 测试 block 自闭合标签编译
     */
    public function testBlockSelfClosingTagCompile(): void
    {
        $content = '<block class="Weline\Demo\Block\Demo" template="Weline_Demo::templates/demo.phtml"/>';
        $fileName = 'test.phtml';
        
        $result = $this->taglib->compile($this->template, $content, $fileName);
        
        self::assertStringContainsString('framework_view_process_block', $result);
    }

    /**
     * 测试 lang 标签编译
     */
    public function testLangTagCompile(): void
    {
        $content = '<lang>Hello World</lang>';
        $fileName = 'test.phtml';
        
        $result = $this->taglib->compile($this->template, $content, $fileName);
        
        self::assertStringContainsString('Hello World', $result);
    }

    /**
     * 测试 if 标签编译
     */
    public function testIfTagCompile(): void
    {
        $content = '<if condition="$show">Content</if>';
        $fileName = 'test.phtml';
        
        $result = $this->taglib->compile($this->template, $content, $fileName);
        
        self::assertStringContainsString('if(', $result);
        self::assertStringContainsString('endif', $result);
    }

    /**
     * 测试带 PHP 代码的 block 成对标签编译
     */
    public function testBlockPairedTagWithPhpCompile(): void
    {
        $php = '<' . '?php $test = 1; ?' . '>';
        $content = $php . "\n<block>Weline\Backend\Block\Header\Base</block>";
        $fileName = 'test.phtml';
        
        // 编译应该不抛出异常
        $result = $this->taglib->compile($this->template, $content, $fileName);
        
        // 结果应该包含 framework_view_process_block 调用
        self::assertStringContainsString('framework_view_process_block', $result, 
            '带 PHP 代码的 block 成对标签应该生成 framework_view_process_block 调用');
    }

    /**
     * 测试 header/base.phtml 模板场景
     */
    public function testHeaderBaseTemplateScenario(): void
    {
        $php = '<' . '?php
/**@var \Weline\Framework\View\Template $this */
/**@var \Weline\Backend\Block\ThemeConfig $themeConfig */
$themeConfig = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Backend\Block\ThemeConfig::class);
?' . '>';
        $content = $php . "\n<block>Weline\Backend\Block\Header\Base</block>\n<script>console.log('test');</script>";
        $fileName = 'header/base.phtml';
        
        // 编译应该不抛出异常
        $result = $this->taglib->compile($this->template, $content, $fileName);
        
        // 结果应该包含 framework_view_process_block 调用
        self::assertStringContainsString('framework_view_process_block', $result, 
            'header/base.phtml 模板应该生成 framework_view_process_block 调用');
    }

    /**
     * 测试 block 标签 selfClosing 属性
     * 
     * 验证成对标签的 selfClosing 是 false
     */
    public function testBlockTagSelfClosingProperty(): void
    {
        $content = '<block>TestClass</block>';
        $fileName = 'test.phtml';
        
        // 使用反射检查编译过程中的 TagNode
        $taglib = $this->taglib;
        $template = $this->template;
        
        // 编译应该成功
        $result = $taglib->compile($template, $content, $fileName);
        
        // 验证结果包含正确的输出
        self::assertStringContainsString('framework_view_process_block', $result);
        self::assertStringNotContainsString('未指定block类', $result, '不应该出现错误信息');
    }

    /**
     * 测试连续多次编译
     * 
     * 模拟嵌套模板编译场景
     */
    public function testMultipleCompilations(): void
    {
        $taglib = $this->taglib;
        $template = $this->template;
        
        // 第一次编译：主模板
        $content1 = '<lang>Hello</lang>';
        $result1 = $taglib->compile($template, $content1, 'main.phtml');
        self::assertStringContainsString('Hello', $result1);
        
        // 第二次编译：子模板（包含 block 成对标签）
        $php = '<' . '?php $test = 1; ?' . '>';
        $content2 = $php . "\n<block>Weline\Backend\Block\Header\Base</block>";
        $result2 = $taglib->compile($template, $content2, 'child.phtml');
        self::assertStringContainsString('framework_view_process_block', $result2, 
            '连续编译后，block 成对标签应该正确处理');
        
        // 第三次编译：另一个模板
        $content3 = '<if condition="$show">Content</if>';
        $result3 = $taglib->compile($template, $content3, 'another.phtml');
        self::assertStringContainsString('if(', $result3);
    }

    /**
     * 测试读取真实文件并编译
     */
    public function testCompileRealHeaderFile(): void
    {
        $filePath = BP . '/app/code/Weline/Admin/view/blocks/header/base.phtml';
        
        if (!file_exists($filePath)) {
            self::markTestSkipped('Header file not found');
        }
        
        $content = file_get_contents($filePath);
        $taglib = $this->taglib;
        $template = $this->template;
        
        // 编译应该成功
        $result = $taglib->compile($template, $content, $filePath);
        
        // 结果应该包含 framework_view_process_block 调用
        self::assertStringContainsString('framework_view_process_block', $result, 
            'header/base.phtml 应该成功编译 block 成对标签');
    }

    /**
     * 调试：检查 AST 中 block 标签的 selfClosing 属性
     */
    public function testDebugBlockAstSelfClosing(): void
    {
        $php = '<' . '?php $test = 1; ?' . '>';
        $content = $php . "\n<block>Weline\Backend\Block\Header\Base</block>";
        
        // 模拟编译流程
        $extractor = new \Weline\Framework\View\Taglib\Parser\PhpExtractor();
        $cleanContent = $extractor->extract($content);
        
        $tokenizer = new \Weline\Framework\View\Taglib\Parser\Tokenizer();
        $tokenizer->setFrameworkTags(['block', 'lang', 'if', 'foreach', 'template']);
        $tokens = $tokenizer->tokenizeToArray($cleanContent);
        
        $astBuilder = new \Weline\Framework\View\Taglib\Parser\AstBuilder();
        $astBuilder->setPhpExtractor($extractor);
        $ast = $astBuilder->build($tokens, 'test.phtml');
        
        // 查找 block TagNode
        $blockNode = null;
        foreach ($ast->children as $child) {
            if ($child instanceof \Weline\Framework\View\Taglib\Ast\TagNode && $child->name === 'block') {
                $blockNode = $child;
                break;
            }
        }
        
        self::assertNotNull($blockNode, 'Should find block tag node');
        self::assertFalse($blockNode->selfClosing, 'Block paired tag should have selfClosing=false');
        self::assertNotEmpty($blockNode->children, 'Block should have children');
    }

    /**
     * 测试嵌套标签在属性值中的编译
     * 
     * 验证 AttrNode.value 的 readonly 属性问题
     */
    public function testNestedTagInAttributeValue(): void
    {
        // 带有嵌套 lang 标签的属性值
        $content = '<block template="<lang>hello</lang>">Content</block>';
        $fileName = 'test.phtml';
        
        $taglib = $this->taglib;
        $template = $this->template;
        
        // 编译应该成功，不抛出 "Cannot modify readonly property" 异常
        $result = $taglib->compile($template, $content, $fileName);
        
        // 结果应该包含 framework_view_process_block 调用
        self::assertStringContainsString('framework_view_process_block', $result);
    }

    /**
     * 测试动态内容在属性值中的编译
     */
    public function testDynamicPhpInAttributeValue(): void
    {
        $php = '<' . '?= $title ?' . '>';
        $content = '<block template="' . $php . '">Content</block>';
        $fileName = 'test.phtml';
        
        $taglib = $this->taglib;
        $template = $this->template;
        
        // 编译应该成功
        $result = $taglib->compile($template, $content, $fileName);
        
        // 应该包含动态 PHP 表达式
        self::assertStringContainsString('$title', $result);
    }
}
