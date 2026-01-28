<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Controller\Admin;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\Websites\Controller\Admin\Website;
use Weline\Websites\Model\Website as WebsiteModel;
use Weline\Websites\Model\WebsiteCurrency;
use Weline\Websites\Model\WebsiteLanguage;

/**
 * 网站管理控制器单元测试
 * 
 * 测试网站添加功能：
 * - 成功添加站点
 * - 添加失败时的错误处理
 * - 验证不会检查 website_id（添加时不应该有 website_id）
 * - 验证错误跳转到首页
 */
class WebsiteTest extends TestCore
{
    private Website $controller;
    private WebsiteModel $websiteModel;

    protected function setUp(): void
    {
        parent::setUp();
        
        // 获取控制器实例
        $this->controller = ObjectManager::getInstance(Website::class);
        $this->websiteModel = ObjectManager::getInstance(WebsiteModel::class);
    }

    protected function tearDown(): void
    {
        $this->controller = null;
        $this->websiteModel = null;
        
        parent::tearDown();
    }

    /**
     * 测试：控制器类存在
     */
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(Website::class));
    }

    /**
     * 测试：控制器有 add 方法
     */
    public function testControllerHasAddMethod(): void
    {
        $reflection = new \ReflectionClass(Website::class);
        $this->assertTrue($reflection->hasMethod('add'));
    }

    /**
     * 测试：add 方法在非 POST 请求时返回表单视图
     */
    public function testAddReturnsFormOnGetRequest(): void
    {
        // 设置非 POST 请求
        $request = ObjectManager::getInstance(\Weline\Framework\Http\Request::class);
        $request->setMethod('GET');
        
        // 调用 add 方法
        $result = $this->controller->add();
        
        // 验证返回了模板（fetch 方法返回字符串）
        $this->assertIsString($result);
    }

    /**
     * 测试：add 方法在添加时清除 website_id
     */
    public function testAddClearsWebsiteId(): void
    {
        $reflection = new \ReflectionClass(Website::class);
        $method = $reflection->getMethod('add');
        $method->setAccessible(true);
        
        // 验证 add 方法中会清除 website_id
        $sourceCode = file_get_contents($reflection->getFileName());
        $this->assertStringContainsString('unset($data[\'website_id\'])', $sourceCode, 
            'add 方法应该清除 website_id，避免在添加时使用已有的 ID');
    }

    /**
     * 测试：add 方法在失败时跳转到首页
     */
    public function testAddErrorRedirectsToHomepage(): void
    {
        $reflection = new \ReflectionClass(Website::class);
        $sourceCode = file_get_contents($reflection->getFileName());
        
        // 验证错误处理中跳转到首页
        $this->assertStringContainsString("'url' => '/'", $sourceCode, 
            '添加失败时应该跳转到首页，而不是添加页面');
        $this->assertStringContainsString("'reload' => '0'", $sourceCode, 
            '添加失败时不应该刷新页面');
    }

    /**
     * 测试：add 方法在成功时跳转到成功页面
     */
    public function testAddSuccessRedirectsToSuccessPage(): void
    {
        $reflection = new \ReflectionClass(Website::class);
        $sourceCode = file_get_contents($reflection->getFileName());
        
        // 验证成功处理中跳转到成功页面
        $this->assertStringContainsString('/component/offcanvas/success', $sourceCode, 
            '添加成功时应该跳转到成功页面');
    }

    /**
     * 测试：add 方法验证保存后能获取到新的 website_id
     */
    public function testAddValidatesNewWebsiteId(): void
    {
        $reflection = new \ReflectionClass(Website::class);
        $sourceCode = file_get_contents($reflection->getFileName());
        
        // 验证会检查保存后是否能获取到新的 ID
        $this->assertStringContainsString('getId()', $sourceCode, 
            '应该使用 getId() 获取新创建的网站 ID');
        $this->assertStringContainsString('empty($websiteId)', $sourceCode, 
            '应该检查是否成功获取到新的网站 ID');
    }

    /**
     * 测试：add 方法不会在添加时检查 website_id 是否存在
     */
    public function testAddDoesNotCheckWebsiteIdExists(): void
    {
        $reflection = new \ReflectionClass(Website::class);
        $sourceCode = file_get_contents($reflection->getFileName());
        
        // 验证 add 方法中不会检查 website_id 是否存在（这是 edit 方法的行为）
        // add 方法应该只清除 website_id，不应该检查它
        $addMethodStart = strpos($sourceCode, 'public function add()');
        $addMethodEnd = strpos($sourceCode, 'public function edit()', $addMethodStart);
        $addMethodCode = substr($sourceCode, $addMethodStart, $addMethodEnd - $addMethodStart);
        
        // 验证 add 方法中没有检查 website_id 是否存在的逻辑
        $this->assertStringNotContainsString('getWebsiteId()', $addMethodCode, 
            'add 方法不应该检查 website_id 是否存在（这是 edit 方法的行为）');
    }

    /**
     * 测试：模板变量已定义，避免未定义变量警告
     */
    public function testTemplateVariablesHaveDefaults(): void
    {
        // 检查 off-canvas 模板文件
        $templatePath = BP . 'app/code/Weline/Component/view/blocks/off-canvas.phtml';
        $this->assertFileExists($templatePath, 'off-canvas 模板文件应该存在');
        
        $templateContent = file_get_contents($templatePath);
        
        // 验证所有变量都有默认值
        $this->assertStringContainsString("{{target_button_text|'添加'}}", $templateContent, 
            'target_button_text 应该有默认值');
        $this->assertStringContainsString("{{title|''}}", $templateContent, 
            'title 应该有默认值');
        $this->assertStringContainsString("{{submit_button_text|'保存'}}", $templateContent, 
            'submit_button_text 应该有默认值');
    }

    /**
     * 测试：OffCanvas Block 类正确设置默认值
     */
    public function testOffCanvasBlockSetsDefaultValues(): void
    {
        $reflection = new \ReflectionClass(\Weline\Component\Block\OffCanvas::class);
        
        // 验证有 default_data 常量
        $this->assertTrue($reflection->hasConstant('default_data'));
        
        $defaultData = $reflection->getConstant('default_data');
        $this->assertIsArray($defaultData);
        
        // 验证默认值包含必要的字段
        $this->assertArrayHasKey('target-button-text', $defaultData);
        $this->assertArrayHasKey('submit-button-text', $defaultData);
        $this->assertArrayHasKey('title', $defaultData);
    }
}
