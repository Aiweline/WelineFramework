<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\View\test;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\Framework\View\Taglib;
use Weline\Framework\View\Template;

/**
 * Hook 名称提取单元测试
 * 
 * 测试 hook 标签解析时，hook 名称提取逻辑是否正确
 * 确保不会将 else 内容或其他内容混入 hook 名称
 */
class HookNameExtractionTest extends TestCore
{
    private Taglib $taglib;
    private Template $template;

    public function setUp(): void
    {
        parent::setUp();
        $this->taglib = ObjectManager::getInstance(Taglib::class);
        $this->template = ObjectManager::getInstance(Template::class);
    }

    /**
     * 测试 hook 名称提取逻辑 - 有 else 标签的情况
     * 
     * 注意：由于测试环境中的 hook 可能不存在，会返回 else_content
     * 我们验证的是：返回的 else_content 不包含错误的 hook 名称（说明提取逻辑正确）
     */
    public function testHookNameExtractionWithElse()
    {
        // 测试用例1: 简单的 hook 名称，有 else 标签
        $content = '<w:hook>header-language-switcher<else/><button class="language-switcher-btn">Test</button></w:hook>';
        
        $parsed = $this->taglib->tagReplace($this->template, $content);
        
        // 验证：如果 hook 存在，应该返回 getHook 调用；如果不存在，返回 else_content
        // 关键验证：不应该出现错误的 hook 名称（如包含 <button 或其他内容）
        $this->assertStringNotContainsString("getHook('header-language-switcher<button", $parsed,
            'Hook 名称不应该包含 HTML 标签');
        $this->assertStringNotContainsString("getHook('header-language-switcher<else", $parsed,
            'Hook 名称不应该包含 else 标签');
        // 验证 else_content 被正确返回（hook 不存在时）
        $this->assertStringContainsString('Test</button>', $parsed,
            '当 hook 不存在时，应该返回 else_content');
    }

    /**
     * 测试 hook 名称提取 - 没有 else 标签，但内容中有 HTML
     * 
     * 这是导致 "header-cartcart" 错误的实际场景
     * 关键验证：hook 名称提取时不应该将后续的 HTML 内容混入
     */
    public function testHookNameExtractionWithoutElseButWithHtml()
    {
        // 测试用例2: hook 名称后直接跟 HTML 标签（没有 else）- 这是实际导致错误的场景
        $content = '<w:hook>header-cart<a href="/cart" class="cart-link">Test</a></w:hook>';
        
        $parsed = $this->taglib->tagReplace($this->template, $content);
        
        // 关键验证：不应该出现错误的 hook 名称（这是导致 "header-cartcart" 错误的根本原因）
        $this->assertStringNotContainsString("getHook('header-cart<a", $parsed,
            'Hook 名称不应该包含 HTML 标签');
        $this->assertStringNotContainsString("getHook('header-cartcart", $parsed,
            'Hook 名称不应该包含后续内容中的 cart（这是导致错误的根本原因）');
        // 验证 else_content 被正确返回（hook 不存在时，HTML 内容应该被返回）
        $this->assertStringContainsString('<a href="/cart"', $parsed,
            '当 hook 不存在时，应该返回 else_content（HTML 内容）');
    }

    /**
     * 测试 hook 名称提取 - 完整格式的 hook 名称
     * 
     * 验证完整格式的 hook 名称（包含冒号和连字符）能被正确提取
     */
    public function testHookNameExtractionFullFormat()
    {
        // 测试用例3: 完整格式的 hook 名称
        $content = '<w:hook>Weline_Theme::frontend::partials::header::categories-before</w:hook>';
        
        $parsed = $this->taglib->tagReplace($this->template, $content);
        
        // 验证：不应该出现错误的 hook 名称（如包含 else 标签或其他内容）
        $this->assertStringNotContainsString("getHook('Weline_Theme::frontend::partials::header::categories-before<else", $parsed,
            'Hook 名称不应该包含 else 标签');
        // 如果 hook 存在，应该返回正确的格式；如果不存在，返回 else_content（空）
        // 这里主要验证提取逻辑不会破坏完整格式
        $this->assertIsString($parsed, '应该能正常解析完整格式的 hook 名称');
    }

    /**
     * 测试 hook 名称提取 - hook 名称后跟 PHP 代码
     * 
     * 验证 hook 名称提取时不会将后续的 PHP 代码混入
     */
    public function testHookNameExtractionWithPhpCode()
    {
        // 测试用例4: hook 名称后跟 PHP 代码（没有 else）
        $content = '<w:hook>header-currency-switcher<?= $frontendUrl ?>cart</w:hook>';
        
        $parsed = $this->taglib->tagReplace($this->template, $content);
        
        // 关键验证：不应该出现错误的 hook 名称（包含 PHP 代码或后续内容）
        $this->assertStringNotContainsString("getHook('header-currency-switcher<?=", $parsed,
            'Hook 名称不应该包含 PHP 代码');
        $this->assertStringNotContainsString("getHook('header-currency-switchercart", $parsed,
            'Hook 名称不应该包含后续内容中的 cart');
        // 验证 else_content 被正确返回（hook 不存在时）
        $this->assertStringContainsString('$frontendUrl', $parsed,
            '当 hook 不存在时，应该返回 else_content（PHP 代码）');
    }

    /**
     * 测试 hook 名称提取 - 单标签格式
     * 
     * 验证单标签格式（没有 else）的 hook 名称提取
     */
    public function testHookNameExtractionSingleTag()
    {
        // 测试用例5: 单标签格式（向后兼容）
        $content = '<w:hook>header-cart</w:hook>';
        
        $parsed = $this->taglib->tagReplace($this->template, $content);
        
        // 验证：单标签格式应该能正常解析
        // 如果 hook 存在，返回 getHook 调用；如果不存在，返回空字符串
        $this->assertIsString($parsed, '单标签格式应该能正常解析');
        // 不应该出现错误的 hook 名称
        $this->assertStringNotContainsString("getHook('header-cart<", $parsed,
            'Hook 名称不应该包含其他内容');
    }

    /**
     * 测试 hook 名称提取 - 复杂情况：hook 名称后跟多种内容
     * 
     * 验证 hook 名称提取时不会将 else 后的复杂内容混入
     */
    public function testHookNameExtractionComplexCase()
    {
        // 测试用例6: hook 名称后跟 HTML 和 PHP 代码混合
        $content = '<w:hook>header-cart<else/><a href="<?= $frontendUrl ?>cart" class="cart-link" title="<?= __(\'购物车\') ?>">
            <i class="fas fa-shopping-cart"></i>
            <span class="cart-text"><?= __(\'购物车\') ?></span>
            <span class="cart-count">0</span>
        </a></w:hook>';
        
        $parsed = $this->taglib->tagReplace($this->template, $content);
        
        // 关键验证：不应该出现错误的 hook 名称（这是核心测试点）
        $this->assertStringNotContainsString("getHook('header-cart<", $parsed,
            'Hook 名称不应该包含 HTML 标签');
        $this->assertStringNotContainsString("getHook('header-cart<?=", $parsed,
            'Hook 名称不应该包含 PHP 代码');
        $this->assertStringNotContainsString("getHook('header-cartcart", $parsed,
            'Hook 名称不应该包含后续内容中的 cart（这是导致错误的根本原因）');
        // 验证 else_content 被正确返回（hook 不存在时）
        $this->assertStringContainsString('购物车', $parsed,
            '当 hook 不存在时，应该返回 else_content');
    }

    /**
     * 测试 hook 名称提取 - 包含特殊字符的情况
     * 
     * 验证 hook 名称中的特殊字符（冒号和连字符）能被正确保留
     */
    public function testHookNameExtractionWithSpecialCharacters()
    {
        // 测试用例7: hook 名称包含连字符和冒号
        $content = '<w:hook>Weline_Theme::frontend::partials::header::categories-before</w:hook>';
        
        $parsed = $this->taglib->tagReplace($this->template, $content);
        
        // 验证：不应该出现错误的 hook 名称（如包含 else 标签）
        $this->assertStringNotContainsString("getHook('Weline_Theme::frontend::partials::header::categories-before<else", $parsed,
            'Hook 名称不应该包含 else 标签');
        // 验证提取逻辑不会破坏特殊字符（如果 hook 存在，应该保留冒号和连字符）
        $this->assertIsString($parsed, '应该能正常解析包含特殊字符的 hook 名称');
    }

    /**
     * 测试 hook 名称提取 - 边界情况：空内容
     */
    public function testHookNameExtractionEmptyContent()
    {
        // 测试用例8: 空内容
        $content = '<w:hook></w:hook>';
        
        $parsed = $this->taglib->tagReplace($this->template, $content);
        
        // 验证应该能正常处理空内容
        $this->assertIsString($parsed, '空内容应该能正常解析');
    }

    /**
     * 测试 hook 名称提取 - 边界情况：只有 else，没有 hook 名称
     */
    public function testHookNameExtractionOnlyElse()
    {
        // 测试用例9: 只有 else 标签，没有 hook 名称
        $content = '<w:hook><else/><button>Test</button></w:hook>';
        
        $parsed = $this->taglib->tagReplace($this->template, $content);
        
        // 验证应该能正常处理
        $this->assertIsString($parsed, '只有 else 的情况应该能正常解析');
    }

    /**
     * 测试 hook 名称提取 - 实际使用场景：header-cart（导致 header-cartcart 错误）
     * 
     * 这是导致 "header-cartcart" 错误的实际场景
     * 关键验证：hook 名称提取时不应该将后续内容中的 "cart" 混入
     */
    public function testMultilineHookFallbackWithPhpDoesNotEmitStandaloneElse()
    {
        $content = <<<'TPL'
<w:hook>
    Missing_Module::frontend::partials::header::categories-before-unit
    <else/>
    <div class="header-categories">
    <?php if (!empty($navItems)): ?>
        <nav>ok</nav>
    <?php endif; ?>
    </div>
</w:hook>
TPL;

        $parsed = $this->taglib->tagReplace($this->template, $content);

        $this->assertStringNotContainsString(
            'Missing_Module::frontend::partials::header::categories-before-unit',
            $parsed,
            'Multiline hook fallback must not leak the hook name into the compiled fallback'
        );
        $this->assertStringNotContainsString(
            '<?php else: ?>',
            $parsed,
            'The hook delimiter must not be compiled as a standalone PHP else'
        );

        $tmpFile = tempnam(sys_get_temp_dir(), 'weline-hook-');
        $this->assertIsString($tmpFile);
        file_put_contents($tmpFile, $parsed);
        exec(PHP_BINARY . ' -l ' . escapeshellarg($tmpFile), $output, $exitCode);
        @unlink($tmpFile);
        $this->assertSame(0, $exitCode, implode("\n", $output));
    }

    public function testHookNameExtractionRealWorldCase1()
    {
        // 测试用例10: 实际场景 - header-cart（这是导致 "header-cartcart" 错误的场景）
        $content = '<w:hook>header-cart<else/><a href="/cart" class="cart-link">Test</a></w:hook>';
        
        $parsed = $this->taglib->tagReplace($this->template, $content);
        
        // 关键验证：不应该出现错误的 hook 名称（这是导致错误的根本原因）
        $this->assertStringNotContainsString("getHook('header-cartcart", $parsed,
            'Hook 名称不应该是 header-cartcart（不能包含后续内容中的 cart）');
        $this->assertStringNotContainsString("getHook('header-cart<a", $parsed,
            'Hook 名称不应该包含 HTML 标签');
        // 验证 else_content 被正确返回（hook 不存在时）
        $this->assertStringContainsString('Test</a>', $parsed,
            '当 hook 不存在时，应该返回 else_content');
    }

    /**
     * 测试 hook 名称提取 - 实际使用场景：header-currency-switcher（导致 header-currency-switcherCNY 错误）
     * 
     * 这是导致 "header-currency-switcherCNY" 错误的实际场景
     * 关键验证：hook 名称提取时不应该将后续内容中的 "CNY" 混入
     */
    public function testHookNameExtractionRealWorldCase2()
    {
        // 测试用例11: 实际场景 - header-currency-switcher（这是导致 "header-currency-switcherCNY" 错误的场景）
        $content = '<w:hook>header-currency-switcher<else/><button class="currency-switcher-btn">
            <i class="fas fa-dollar-sign"></i>
            <span>CNY</span>
        </button></w:hook>';
        
        $parsed = $this->taglib->tagReplace($this->template, $content);
        
        // 关键验证：不应该出现错误的 hook 名称（这是导致错误的根本原因）
        $this->assertStringNotContainsString("getHook('header-currency-switcherCNY", $parsed,
            'Hook 名称不应该是 header-currency-switcherCNY（不能包含后续内容中的 CNY）');
        $this->assertStringNotContainsString("getHook('header-currency-switcher<button", $parsed,
            'Hook 名称不应该包含 HTML 标签');
        // 验证 else_content 被正确返回（hook 不存在时）
        $this->assertStringContainsString('CNY</span>', $parsed,
            '当 hook 不存在时，应该返回 else_content');
    }
}

