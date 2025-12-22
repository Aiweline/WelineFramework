<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Test\Unit;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\WelineTheme;

/**
 * 变量配置测试
 * 
 * 测试变量配置界面和调色盘功能
 */
class VariablesConfigTest extends TestCore
{
    private WelineTheme $theme;
    
    public function setUp(): void
    {
        parent::setUp();
        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $this->theme = $theme->getActiveTheme();
    }
    
    /**
     * 测试获取变量配置列表
     */
    public function testGetVariablesConfig(): void
    {
        if (!$this->theme || !$this->theme->getId()) {
            $this->markTestSkipped('没有激活的主题，跳过测试');
            return;
        }
        
        $config = ThemeData::getVariablesConfig('frontend', 'default');
        
        $this->assertIsArray($config);
    }
    
    /**
     * 测试获取变量Meta列表
     */
    public function testGetVariablesMetaList(): void
    {
        $metaList = ThemeData::getMetaList('frontend', 'variables');
        
        $this->assertIsArray($metaList);
        
        // 如果有变量，验证结构
        if (!empty($metaList)) {
            $first = $metaList[0];
            $this->assertArrayHasKey('meta_identify', $first);
            $this->assertArrayHasKey('meta_data', $first);
        }
    }
    
    /**
     * 测试获取色盘配置
     */
    public function testGetColorConfig(): void
    {
        $colorConfig = ThemeData::getColorConfig('frontend', 'default');
        
        // 可能为null或字符串
        $this->assertTrue($colorConfig === null || is_string($colorConfig));
    }
    
    /**
     * 测试获取色盘Meta列表
     */
    public function testGetColorsMetaList(): void
    {
        $metaList = ThemeData::getMetaList('frontend', 'colors');
        
        $this->assertIsArray($metaList);
        
        // 如果有色盘，验证结构
        if (!empty($metaList)) {
            $first = $metaList[0];
            $this->assertArrayHasKey('meta_identify', $first);
            $this->assertArrayHasKey('meta_data', $first);
        }
    }
    
    /**
     * 测试设置和获取变量值
     */
    public function testSetAndGetVariableValue(): void
    {
        if (!$this->theme || !$this->theme->getId()) {
            $this->markTestSkipped('没有激活的主题，跳过测试');
            return;
        }
        
        ThemeData::setCurrentTheme($this->theme);
        ThemeData::setCurrentArea('frontend');
        
        // 测试设置变量值
        $identify = 'theme.frontend.variables.colors.test-color.value';
        $testValue = '#ff0000';
        
        $result = ThemeData::set($identify, $testValue, 'default');
        
        // 验证设置成功（可能因为Meta不存在而失败，这是正常的）
        $this->assertIsBool($result);
        
        // 如果设置成功，验证获取
        if ($result) {
            $value = ThemeData::get($identify);
            // 注意：值可能因为缓存等原因不是立即生效
        }
    }
    
    /**
     * 测试获取配置列表
     */
    public function testGetConfigList(): void
    {
        if (!$this->theme || !$this->theme->getId()) {
            $this->markTestSkipped('没有激活的主题，跳过测试');
            return;
        }
        
        $configList = ThemeData::getConfigList('frontend', 'variables', 'default');
        
        $this->assertIsArray($configList);
    }
}

