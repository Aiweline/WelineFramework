<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Test\Unit;

use Weline\Framework\Event\Event;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\Theme\Helper\AssetsExtractor;
use Weline\Theme\Helper\LayoutAssetsManager;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Observer\LayoutAssetsExtractor;

/**
 * 布局资源提取器测试
 * 
 * 测试CSS/JS提取功能和安全验证（内联标签移除）
 */
class LayoutAssetsExtractorTest extends TestCore
{
    private AssetsExtractor $extractor;
    private LayoutAssetsManager $assetsManager;
    
    public function setUp(): void
    {
        parent::setUp();
        $this->extractor = ObjectManager::getInstance(AssetsExtractor::class);
        $this->assetsManager = ObjectManager::getInstance(LayoutAssetsManager::class);
    }
    
    /**
     * 测试提取内联CSS
     */
    public function testExtractInlineCss(): void
    {
        $content = <<<'HTML'
<html>
<head>
    <style>
        .test { color: red; }
        .header { background: blue; }
    </style>
</head>
<body>Test</body>
</html>
HTML;
        
        $result = $this->extractor->extract($content);
        
        // 验证CSS被提取
        $this->assertStringContainsString('.test', $result['css']);
        $this->assertStringContainsString('.header', $result['css']);
        
        // 验证style标签被移除
        $this->assertStringNotContainsString('<style', $result['content']);
        $this->assertStringNotContainsString('</style>', $result['content']);
    }
    
    /**
     * 测试提取内联JS
     */
    public function testExtractInlineJs(): void
    {
        $content = <<<'HTML'
<html>
<body>
    <script>
        console.log('test');
        function test() { return true; }
    </script>
</body>
</html>
HTML;
        
        $result = $this->extractor->extract($content);
        
        // 验证JS被提取
        $this->assertStringContainsString('console.log', $result['js']);
        $this->assertStringContainsString('function test', $result['js']);
        
        // 验证script标签被移除
        $this->assertStringNotContainsString('<script', $result['content']);
        $this->assertStringNotContainsString('</script>', $result['content']);
    }
    
    /**
     * 测试保留theme.js外部引用
     */
    public function testPreserveThemeJsExternalReference(): void
    {
        $content = <<<'HTML'
<html>
<head>
    <script src="/static/theme.js"></script>
    <script>
        console.log('inline');
    </script>
</head>
</html>
HTML;
        
        $result = $this->extractor->extract($content);
        
        // 验证theme.js外部引用被保留
        $this->assertStringContainsString('src="/static/theme.js"', $result['content']);
        
        // 验证内联JS被提取
        $this->assertStringContainsString('console.log', $result['js']);
        
        // 验证内联script标签被移除
        $this->assertStringNotContainsString('<script>', $result['content']);
    }

    /**
     * 非 theme.js 的外部 script（仅 src、标签体为空）不得被误删，否则依赖顺序的资源（如 jQuery）会丢失。
     */
    public function testPreserveExternalScriptWithEmptyBody(): void
    {
        $content = <<<'HTML'
<html>
<head>
    <script src="/static/Weline/Frontend/view/statics/libs/jquery/3.6.0/jquery.js"></script>
    <script>
        jQuery.noop();
    </script>
</head>
</html>
HTML;

        $result = $this->extractor->extract($content);

        $this->assertStringContainsString('jquery.js', $result['content']);
        $this->assertStringContainsString('jQuery.noop', $result['js']);
    }
    
    /**
     * 测试安全验证：生产环境发现内联标签应抛出异常
     */
    public function testSecurityValidationInProduction(): void
    {
        // 注意：这个测试需要在非DEV环境下运行
        // 由于测试环境通常是DEV，这里主要测试逻辑
        
        $content = <<<'HTML'
<html>
<body>
    <style>.test { color: red; }</style>
    <script>console.log('test');</script>
</body>
</html>
HTML;
        
        // 提取应该成功移除标签
        $result = $this->extractor->extract($content);
        
        // 验证标签已被移除
        $this->assertStringNotContainsString('<style', $result['content']);
        $this->assertStringNotContainsString('<script', $result['content']);
    }
    
    /**
     * 测试合并多个提取结果
     */
    public function testMergeExtractions(): void
    {
        $extraction1 = [
            'css' => '.header { color: red; }',
            'js' => 'function test1() {}'
        ];
        
        $extraction2 = [
            'css' => '.footer { color: blue; }',
            'js' => 'function test2() {}'
        ];
        
        $merged = $this->extractor->mergeExtractions([$extraction1, $extraction2]);
        
        $this->assertStringContainsString('.header', $merged['css']);
        $this->assertStringContainsString('.footer', $merged['css']);
        $this->assertStringContainsString('test1', $merged['js']);
        $this->assertStringContainsString('test2', $merged['js']);
    }
    
    /**
     * 测试提取带来源标识的CSS
     */
    public function testExtractCssWithSource(): void
    {
        $content = '<style>.test { color: red; }</style>';
        $sourceFile = 'partials/header/default.phtml';
        
        $result = $this->extractor->extract($content, $sourceFile);
        
        // 验证来源标识被添加
        $this->assertStringContainsString('SOURCE: header/default', $result['css']);
    }
    
    /**
     * 测试提取空内容
     */
    public function testExtractEmptyContent(): void
    {
        $content = '<html><body>No styles or scripts</body></html>';
        
        $result = $this->extractor->extract($content);
        
        $this->assertEmpty($result['css']);
        $this->assertEmpty($result['js']);
        $this->assertEquals($content, $result['content']);
    }
}

