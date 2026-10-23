<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\View;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;

class TaglibTest extends TestCore
{
    private Taglib $taglib;

    public function setUp(): void
    {
        parent::setUp();
        $this->taglib = ObjectManager::getInstance(Taglib::class);
    }

    public function testVarParser()
    {
        $parse_str = $this->taglib->varParser('Request.param.c_id');
        self::assertTrue($parse_str === "(\$Request['param']['c_id']??'') ", '解析变量');
    }

    public function testVarParserKeepsTranslationHelperCallable(): void
    {
        // Countries/Localization templates pass @var(__('...')) into w:form attrs.
        self::assertSame("__('已停用')", $this->taglib->checkVar("__('已停用')"));
        self::assertSame("__('已停用')", trim($this->taglib->varParser("__('已停用')")));
        self::assertSame(
            "__('确定停用 %{1} 吗？', [\$displayName])",
            trim($this->taglib->varParser("__('确定停用 %{1} 吗？', [\$displayName])"))
        );
        self::assertSame('count($items)', $this->taglib->checkVar('count($items)'));
        self::assertSame('$displayName', $this->taglib->checkVar('displayName'));
    }

    public function testTagIf()
    {
        $template = new Template();
        //        $content = '@if{req.type==="progress-select-entity"=>"active"}';
        //        $content = "@if{req.type==='progress-select-entity'=>'active'}";
        //        $content = "@if{type==='progress-select-entity'=>'active'}";
        $content = '@if{country.is_active.r!==1 and a==1 =>1|0}';
        $parse_str = $this->taglib->tagReplace($template, $content);
        self::assertSame(
            "<?php if((\$country['is_active']['r']??'') !== 1 and \$a == 1  ):echo 1; else: echo 0; endif;?>",
            $parse_str,
            '解析变量'
        );
    }

    /**
     * Summary of testArrow
     * @return void
     */
    public function testArrow()
    {
        $template = new Template();
        //        $content = '@if{req.type==="progress-select-entity"=>"active"}';
        //        $content = "@if{req.type==='progress-select-entity'=>'active'}";
        //        $content = "@if{type==='progress-select-entity'=>'active'}";
        $content = '@if{country->is_active() =>1|0}';
        $parse_str = $this->taglib->tagReplace($template, $content);
        self::assertTrue($parse_str === "<?php if(\$country->is_active()  ):echo 1; else: echo 0; endif;?>", '解析变量');
    }

    public function testVarParserEmptyString()
    {
        $parse_str = $this->taglib->varParser('Request.param.c_id');
        self::assertTrue($parse_str === "(\$Request['param']['c_id']??'') ", '解析变量');
    }

    public function testVarDefaultVarParser()
    {
        $content = '{{attribute.local_name|attribute.name}}';
        $parse_str = $this->taglib->varParser($content);
        self::assertTrue($parse_str === "({{attribute['local_name']??(\$attribute['name}}']??'') ) ");
    }

    /**
     * Summary of testElse
     * @return void
     */
    public function testElse()
    {
        $template = new Template();
        $content = '<else />';
        $parse_str = $this->taglib->tagReplace($template, $content);
        $result1 = $parse_str === "<?php else: ?>";
        $content = '<else/>';
        $parse_str = $this->taglib->tagReplace($template, $content);
        $result2 = $parse_str === "<?php else: ?>";
        self::assertTrue($result1 && $result2, '解析变量');
    }

    public function testDefault()
    {
        $template = new Template();
        // 测试嵌套属性的默认值
        $content = "1111{{setting.url | 'http://www.amayum.com'}}2222";
        $parse_str = $this->taglib->tagReplace($template, $content);
        $result1 = $parse_str === "1111<?php echo (((\$setting ?? \$this->getData('setting') ?? [])['url'] ?? null) ?? 'http://www.amayum.com'); ?>2222";
        // 测试简单变量的默认值
        $content = "1111{{target_button_text | '添加'}}2222";
        $parse_str = $this->taglib->tagReplace($template, $content);
        $result2 = $parse_str === "1111<?php echo ((\$target_button_text ?? \$this->getData('target_button_text')) ?? '添加'); ?>2222";
        $content = "1111@if{setting.url =>'hhh'| 'http://www.amayum.com'}2222";
        $parse_str = $this->taglib->tagReplace($template, $content);
        $result3 = $parse_str === "1111<?php if((\$setting['url']??'')  ):echo 'hhh'; else: echo  'http://www.amayum.com'; endif;?>2222";
        self::assertTrue($result1 && $result2 && $result3, '变量解析默认值通过');
    }

    public function testHtmlVoidTagParsing()
    {
        $template = new Template();
        $content = '<head><meta charset="utf-8"></head>';
        $parse_str = $this->taglib->tagReplace($template, $content);
        self::assertSame($content, $parse_str, 'HTML void tag should not break AST parsing');
    }

    public function testLangStaticCompileTime()
    {
        $template = new Template();
        $content = '<lang>hello</lang>';
        $parse_str = $this->taglib->tagReplace($template, $content);
        self::assertSame(__('hello'), $parse_str, 'static lang should be compile-time');
    }

    public function testLangDynamicInlineRuntime()
    {
        $template = new Template();
        $content = '@lang($name)';
        $parse_str = $this->taglib->tagReplace($template, $content);
        self::assertSame("<?=__(\$name)?>", $parse_str, 'dynamic inline lang should be runtime');
    }

    public function testLangDynamicAttributeRuntime()
    {
        $template = new Template();
        $content = '<lang args="<?= $args ?>">hello</lang>';
        $parse_str = $this->taglib->tagReplace($template, $content);
        self::assertTrue(str_contains($parse_str, 'renderRuntimeTag'), 'dynamic args should be runtime');
        self::assertTrue(str_contains($parse_str, '\'args\' => ($args)'), 'php attribute should be parsed');
    }

    /**
     * block 是**刻意**固定在编译期的标签（CodeGenerator::generateTagNode 硬编码，
     * 见该处注释）：它必须在编译期吐出 framework_view_process_block(..., $vars=['x'=>&$x])，
     * 走 renderRuntimeTag 就拿不到 foreach 的局部变量引用。
     *
     * 因此 block 上的动态属性**不会**被延迟到运行期——这是设计取舍，不是缺陷。
     * 动态属性延迟到运行期由 lang / theme:css / theme:js 等标签覆盖（见本类其它用例）。
     */
    public function testBlockDynamicAttributesStayCompileTimeByDesign(): void
    {
        $template = new Template();
        $content = '<block class="<?= $blockClass ?>" template="test.phtml"/>';
        $parse_str = $this->taglib->tagReplace($template, $content);

        self::assertStringContainsString(
            'framework_view_process_block',
            $parse_str,
            'block 必须在编译期直出 framework_view_process_block（保住 foreach 局部变量引用）'
        );
        self::assertStringNotContainsString(
            'renderRuntimeTag',
            $parse_str,
            'block 不得走运行期，否则 $vars 引用会丢失'
        );
    }

    /**
     * <block title="@lang(词)"/>：@lang 展开为 <?=emit(...)?> / __PHP_N__ 后，block 导出必须
     * 解析为 PHP 表达式，禁止再包进单引号（restore 后嵌套引号 → ParseError）。
     */
    public function testBlockLangAttributeExportsAsPhpExpression(): void
    {
        $template = new Template();
        $content = '<block class="Weline\Component\Block\OffCanvas"'
            . ' template="Weline_Component::off-canvas.phtml"'
            . ' title="@lang(添加域名)"'
            . ' id="cdn_domain_add_offcanvas"'
            . ' action="*/backend/domain/form"'
            . ' target-button-text="@lang(添加域名)"'
            . '/>';
        $parse_str = $this->taglib->tagReplace($template, $content);

        self::assertStringContainsString('framework_view_process_block', $parse_str);
        self::assertStringContainsString("InlinePhraseMarker::emit('添加域名'", $parse_str);
        self::assertDoesNotMatchRegularExpression(
            "/'title'\\s*=>\\s*'<\?=/",
            $parse_str,
            'title 不得把 <?=emit(...)?> 再包进单引号字符串'
        );
        self::assertMatchesRegularExpression(
            "/'title'\\s*=>\\s*\\(\\\\Weline\\\\I18n\\\\Service\\\\InlineEdit\\\\InlinePhraseMarker::emit\\('添加域名'/",
            $parse_str,
            'title 应为 emit(...) 表达式'
        );

        self::assertTrue(
            preg_match(
                '/framework_view_process_block\((.*?),\$vars=/s',
                $parse_str,
                $m
            ) === 1,
            '应能抽出 block 属性数组字面量'
        );
        $arrPhp = '<?php $__w_block_attrs = ' . $m[1] . ';';
        $tmp = tempnam(sys_get_temp_dir(), 'w_block_lang_');
        self::assertNotFalse($tmp);
        file_put_contents($tmp, $arrPhp);
        $lint = [];
        $code = 0;
        exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $lint, $code);
        @unlink($tmp);
        self::assertSame(0, $code, implode("\n", $lint));
    }

    /**
     * 多行 block 的最后一个 @lang 紧挨 />。?> 会吞掉换行，占位符不能粘上 `/`。
     */
    public function testBlockLangAttributeBeforeSelfCloseStaysValidPhp(): void
    {
        $template = new Template();
        $content = "<block class='Weline\\Component\\Block\\OffCanvas'\n"
            . "       template='Weline_Component::off-canvas.phtml'\n"
            . "       title='@lang(添加域名)'\n"
            . "       target-button-text='@lang(添加域名)'\n"
            . "/>";
        $parse_str = $this->taglib->tagReplace($template, $content);

        self::assertStringNotContainsString("emit('添加域名', true)?>", $parse_str);
        self::assertSame(2, substr_count($parse_str, "InlinePhraseMarker::emit('添加域名', true)"));
        $tmp = tempnam(sys_get_temp_dir(), 'w_block_lang_close_');
        self::assertNotFalse($tmp);
        file_put_contents($tmp, $parse_str);
        $lint = [];
        $code = 0;
        exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $lint, $code);
        @unlink($tmp);
        self::assertSame(0, $code, implode("\n", $lint));
    }

    /**
     * 测试混合内容属性值（文本 + PHP 表达式）
     */
    public function testMixedAttributeValueRuntime()
    {
        $template = new Template();
        $content = '<lang args="前缀<?= $var ?>后缀">hello</lang>';
        $parse_str = $this->taglib->tagReplace($template, $content);
        self::assertTrue(str_contains($parse_str, 'renderRuntimeTag'), '混合属性值应延迟到运行期');
        // 应该生成拼接表达式
        self::assertTrue(
            str_contains($parse_str, "'前缀' . (\$var) . '后缀'"),
            '混合属性值应生成字符串拼接表达式'
        );
    }
}
