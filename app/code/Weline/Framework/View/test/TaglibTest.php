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
use Weline\Framework\UnitTest\TestCore;

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

    public function testTagIf()
    {
        $template = new Template();
        //        $content = '@if{req.type==="progress-select-entity"=>"active"}';
        //        $content = "@if{req.type==='progress-select-entity'=>'active'}";
        //        $content = "@if{type==='progress-select-entity'=>'active'}";
        $content = '@if{country.is_active.r!==1 and a==1 =>1|0}';
        $parse_str = $this->taglib->tagReplace($template, $content);
        self::assertTrue($parse_str === "<?php if((\$country['is_active']['r']??'')  !==  1   and   \$a  ==  1  ):echo 1; else: echo 0; endif;?>", '解析变量');
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
        $result1 = $parse_str === "<?php else:?>";
        $content = '<else/>';
        $parse_str = $this->taglib->tagReplace($template, $content);
        $result2 = $parse_str === "<?php else:?>";
        self::assertTrue($result1 && $result2, '解析变量');
    }

    public function testDefault()
    {
        $template = new Template();
        // 测试嵌套属性的默认值
        $content = "1111{{setting.url | 'http://www.amayum.com'}}2222";
        $parse_str = $this->taglib->tagReplace($template, $content);
        $result1 = $parse_str === "1111<?=(\$setting['url']?? 'http://www.amayum.com')  ;?>2222";
        // 测试简单变量的默认值
        $content = "1111{{target_button_text | '添加'}}2222";
        $parse_str = $this->taglib->tagReplace($template, $content);
        $result2 = $parse_str === "1111<?=\$target_button_text?? '添加' ;?>2222";
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
        self::assertTrue(str_contains($parse_str, '\'args\' => (\$args)'), 'php attribute should be parsed');
    }
}
