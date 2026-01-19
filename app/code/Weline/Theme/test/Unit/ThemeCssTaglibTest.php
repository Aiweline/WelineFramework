<?php
/**
 * ThemeCss 标签库单元测试
 */

namespace Weline\Theme\Test\Unit;

use Weline\Framework\UnitTest\TestCore;
use Weline\Theme\Taglib\ThemeCss;
use Weline\Theme\Taglib\ThemeJs;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Taglib;
use Weline\Framework\View\Template;
use Weline\Taglib\TaglibRegistry;

class ThemeCssTaglibTest extends TestCore
{
    public function setUp(): void
    {
        parent::setUp();
    }

    /**
     * 测试 ThemeCss 标签基本功能
     */
    public function testThemeCssTagBasicFunctionality()
    {
        // 测试标签名称
        $tagName = ThemeCss::name();
        $this->assertEquals('theme:css', $tagName);
        
        // 测试标签类型
        $isTag = ThemeCss::tag();
        $this->assertTrue($isTag);
        
        // 测试callback是否可调用
        $callback = ThemeCss::callback();
        $this->assertIsCallable($callback);
    }

    /**
     * 测试 ThemeCss 标签注册
     */
    public function testThemeCssTagRegistration()
    {
        /** @var TaglibRegistry $registry */
        $registry = ObjectManager::getInstance(TaglibRegistry::class);
        $tags = $registry->getTags();
        
        // 检查标签是否已注册
        $this->assertArrayHasKey('theme:css', $tags, 'theme:css标签应该已注册');
        
        $themeCssConfig = $tags['theme:css'];
        
        $this->assertTrue($themeCssConfig['tag'] ?? false, 'theme:css应该支持成对标签');
        $this->assertTrue(isset($themeCssConfig['callback']), 'theme:css应该有callback函数');
    }

    /**
     * 测试 ThemeCss 标签callback函数
     */
    public function testThemeCssCallback()
    {
        $callback = ThemeCss::callback();
        
        // 测试成对标签的情况
        $tag_data = [
            0 => '<theme:css>Weline_Theme::theme/frontend/assets/css/theme.css</theme:css>',
            1 => '', // 属性部分（空）
            2 => 'Weline_Theme::theme/frontend/assets/css/theme.css' // 内容部分
        ];
        
        try {
            $result = $callback('tag', [], $tag_data, []);
            
            $this->assertIsString($result, 'callback应该返回字符串');
            $this->assertNotEmpty($result, 'callback返回结果不应为空');
            $this->assertStringContainsString('<link', $result, '应该包含link标签');
            $this->assertStringContainsString('href', $result, '应该包含href属性');
        } catch (\Exception $e) {
            $this->fail('callback调用失败: ' . $e->getMessage());
        }
    }

    /**
     * 测试 ThemeCss 标签在模板中的解析
     */
    public function testThemeCssTagParsing()
    {
        /** @var Taglib $taglib */
        $taglib = ObjectManager::getInstance(Taglib::class);
        /** @var Template $template */
        $template = ObjectManager::getInstance(Template::class);
        
        $testContent = '<theme:css>Weline_Theme::theme/frontend/assets/css/theme.css</theme:css>';
        
        try {
            $result = $taglib->parse($template, 'test.phtml', $testContent);
            
            // 检查标签是否被替换
            $this->assertStringNotContainsString('<theme:css>', $result, '原始标签应该被替换');
            $this->assertStringContainsString('<link', $result, '应该包含解析后的link标签');
        } catch (\Exception $e) {
            $this->fail('标签解析失败: ' . $e->getMessage());
        }
    }

    /**
     * 测试 fetchTagSource 方法
     */
    public function testFetchTagSource()
    {
        /** @var Template $template */
        $template = ObjectManager::getInstance(Template::class);
        
        $source = 'Weline_Theme::theme/frontend/assets/css/theme.css';
        
        try {
            $result = $template->fetchTagSource(\Weline\Framework\View\Data\DataInterface::dir_type_THEME, $source);
            
            $this->assertIsString($result, 'fetchTagSource应该返回字符串');
            $this->assertNotEmpty($result, 'fetchTagSource返回结果不应为空');
        } catch (\Exception $e) {
            $this->fail('fetchTagSource调用失败: ' . $e->getMessage());
        }
    }

    /**
     * 测试标签名称中的冒号处理
     */
    public function testTagNameWithColon()
    {
        $tagName = ThemeCss::name();
        
        // 测试正则表达式是否能匹配带冒号的标签名
        $pattern = '/<' . preg_quote($tagName, '/') . '([\s\S]*?)>([\s\S]*?)<\/' . preg_quote($tagName, '/') . '>/m';
        $testContent = '<theme:css>Weline_Theme::theme/frontend/assets/css/theme.css</theme:css>';
        
        $matches = [];
        $matched = preg_match($pattern, $testContent, $matches);
        
        $this->assertTrue($matched > 0, '正则表达式应该能匹配带冒号的标签名');
        $this->assertGreaterThanOrEqual(3, count($matches), '应该至少匹配3个部分（完整匹配、属性、内容）');
    }

    /**
     * 测试 ThemeJs 标签基本功能
     */
    public function testThemeJsTagBasicFunctionality()
    {
        // 测试标签名称
        $tagName = ThemeJs::name();
        $this->assertEquals('theme:js', $tagName);
        
        // 测试标签类型
        $isTag = ThemeJs::tag();
        $this->assertTrue($isTag);
        
        // 测试callback是否可调用
        $callback = ThemeJs::callback();
        $this->assertIsCallable($callback);
    }

    /**
     * 测试 ThemeJs 标签注册
     */
    public function testThemeJsTagRegistration()
    {
        /** @var TaglibRegistry $registry */
        $registry = ObjectManager::getInstance(TaglibRegistry::class);
        $tags = $registry->getTags();
        
        // 检查标签是否已注册
        $this->assertArrayHasKey('theme:js', $tags, 'theme:js标签应该已注册');
        
        $themeJsConfig = $tags['theme:js'];
        
        $this->assertTrue($themeJsConfig['tag'] ?? false, 'theme:js应该支持成对标签');
        $this->assertTrue(isset($themeJsConfig['callback']), 'theme:js应该有callback函数');
    }

    /**
     * 测试 ThemeJs 标签callback函数
     */
    public function testThemeJsCallback()
    {
        $callback = ThemeJs::callback();
        
        // 测试成对标签的情况
        $tag_data = [
            0 => '<theme:js>Weline_Theme::theme/frontend/assets/js/theme.js</theme:js>',
            1 => '', // 属性部分（空）
            2 => 'Weline_Theme::theme/frontend/assets/js/theme.js' // 内容部分
        ];
        
        try {
            $result = $callback('tag', [], $tag_data, []);
            
            $this->assertIsString($result, 'callback应该返回字符串');
            $this->assertNotEmpty($result, 'callback返回结果不应为空');
            $this->assertStringContainsString('<script', $result, '应该包含script标签');
            $this->assertStringContainsString('src', $result, '应该包含src属性');
        } catch (\Exception $e) {
            $this->fail('callback调用失败: ' . $e->getMessage());
        }
    }

    /**
     * 测试 ThemeJs 标签在模板中的解析
     */
    public function testThemeJsTagParsing()
    {
        /** @var Taglib $taglib */
        $taglib = ObjectManager::getInstance(Taglib::class);
        /** @var Template $template */
        $template = ObjectManager::getInstance(Template::class);
        
        $testContent = '<theme:js>Weline_Theme::theme/frontend/assets/js/theme.js</theme:js>';
        
        try {
            $result = $taglib->parse($template, 'test.phtml', $testContent);
            
            // 检查标签是否被替换
            $this->assertStringNotContainsString('<theme:js>', $result, '原始标签应该被替换');
            $this->assertStringContainsString('<script', $result, '应该包含解析后的script标签');
        } catch (\Exception $e) {
            $this->fail('标签解析失败: ' . $e->getMessage());
        }
    }
}
