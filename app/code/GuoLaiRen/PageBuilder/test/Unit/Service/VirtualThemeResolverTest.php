<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Model\VirtualTheme;
use GuoLaiRen\PageBuilder\Service\VirtualThemeResolver;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\WelineTheme;

class VirtualThemeResolverTest extends TestCase
{
    private VirtualThemeResolver $resolver;
    private VirtualTheme $virtualThemeModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->virtualThemeModel = ObjectManager::getInstance(VirtualTheme::class);
        $this->resolver = new VirtualThemeResolver($this->virtualThemeModel);
    }

    public function testResolveVirtualThemeReturnsNullForInvalidId(): void
    {
        $result = $this->resolver->resolveVirtualTheme(0);
        $this->assertNull($result);

        $result = $this->resolver->resolveVirtualTheme(-1);
        $this->assertNull($result);
    }

    public function testResolveVirtualThemeReturnsNullForNonExistentTheme(): void
    {
        $result = $this->resolver->resolveVirtualTheme(999999);
        $this->assertNull($result);
    }

    public function testGetVirtualThemePathReturnsCorrectPath(): void
    {
        $virtualThemeId = 123;
        $expectedPath = BP . 'generated/pagebuilder/virtual_themes' . DS . $virtualThemeId . DS;

        $result = $this->resolver->getVirtualThemePath($virtualThemeId);

        $this->assertEquals($expectedPath, $result);
    }

    public function testGetVirtualThemeRelativePathReturnsCorrectPath(): void
    {
        $virtualThemeId = 456;
        $expectedPath = 'generated/pagebuilder/virtual_themes/456/';

        $result = $this->resolver->getVirtualThemeRelativePath($virtualThemeId);

        $this->assertEquals($expectedPath, $result);
    }

    public function testIsVirtualThemeExistsReturnsFalseForInvalidId(): void
    {
        $this->assertFalse($this->resolver->isVirtualThemeExists(0));
        $this->assertFalse($this->resolver->isVirtualThemeExists(-1));
    }

    public function testIsVirtualThemeExistsReturnsFalseForNonExistentTheme(): void
    {
        $result = $this->resolver->isVirtualThemeExists(999999);
        $this->assertFalse($result);
    }

    public function testEnsureVirtualThemeDirectoriesReturnsFalseForInvalidId(): void
    {
        $this->assertFalse($this->resolver->ensureVirtualThemeDirectories(0));
        $this->assertFalse($this->resolver->ensureVirtualThemeDirectories(-1));
    }

    public function testGetLayoutsPathReturnsCorrectPath(): void
    {
        $virtualThemeId = 789;
        $expectedFrontendPath = BP . 'generated/pagebuilder/virtual_themes' . DS . $virtualThemeId . DS . 'frontend' . DS . 'layouts' . DS;
        $expectedBackendPath = BP . 'generated/pagebuilder/virtual_themes' . DS . $virtualThemeId . DS . 'backend' . DS . 'layouts' . DS;

        $frontendResult = $this->resolver->getLayoutsPath($virtualThemeId, 'frontend');
        $backendResult = $this->resolver->getLayoutsPath($virtualThemeId, 'backend');

        $this->assertEquals($expectedFrontendPath, $frontendResult);
        $this->assertEquals($expectedBackendPath, $backendResult);
    }

    public function testGetComponentsPathReturnsCorrectPath(): void
    {
        $virtualThemeId = 321;
        $expectedFrontendPath = BP . 'generated/pagebuilder/virtual_themes' . DS . $virtualThemeId . DS . 'frontend' . DS . 'components' . DS;
        $expectedBackendPath = BP . 'generated/pagebuilder/virtual_themes' . DS . $virtualThemeId . DS . 'backend' . DS . 'components' . DS;

        $frontendResult = $this->resolver->getComponentsPath($virtualThemeId, 'frontend');
        $backendResult = $this->resolver->getComponentsPath($virtualThemeId, 'backend');

        $this->assertEquals($expectedFrontendPath, $frontendResult);
        $this->assertEquals($expectedBackendPath, $backendResult);
    }

    /**
     * 集成测试：创建虚拟主题并解析
     * 需要数据库连接，标记为集成测试
     *
     * @group integration
     */
    public function testResolveVirtualThemeReturnsWelineThemeAdapter(): void
    {
        // 创建测试虚拟主题
        /** @var VirtualTheme $virtualTheme */
        $virtualTheme = clone ObjectManager::getInstance(VirtualTheme::class);
        $virtualTheme->clearData()->clearQuery();
        $virtualTheme->setName('Test Virtual Theme')
            ->setSessionId(1)
            ->setWebsiteId(1)
            ->setPath('test/virtual-theme')
            ->setSource(VirtualTheme::SOURCE_PAGEBUILDER_AI)
            ->setConfig([
                'test_key' => 'test_value',
                'website_profile' => ['site_title' => 'Test Site'],
            ])
            ->setIsActive(false)
            ->save();

        $virtualThemeId = $virtualTheme->getId();
        $this->assertGreaterThan(0, $virtualThemeId);

        // 解析虚拟主题
        $welineTheme = $this->resolver->resolveVirtualTheme($virtualThemeId);

        // 验证返回的是 WelineTheme 对象
        $this->assertInstanceOf(WelineTheme::class, $welineTheme);

        // 验证伪装属性
        $this->assertEquals(0, $welineTheme->getId());
        $this->assertEquals('Virtual Theme #' . $virtualThemeId, $welineTheme->getName());
        $this->assertEquals('GuoLaiRen_PageBuilder', $welineTheme->getModuleName());
        $this->assertEquals(0, $welineTheme->isActive());

        // 验证路径
        $expectedPath = 'generated/pagebuilder/virtual_themes/' . $virtualThemeId . '/';
        $this->assertEquals($expectedPath, $welineTheme->getOriginPath());

        // 验证配置传递
        $config = $welineTheme->getConfig();
        $this->assertEquals($virtualThemeId, $config['virtual_theme_id']);
        $this->assertTrue($config['is_virtual']);
        $this->assertEquals('Test Virtual Theme', $config['virtual_theme_name']);
        $this->assertEquals(1, $config['session_id']);
        $this->assertEquals(1, $config['website_id']);
        $this->assertEquals('test_value', $config['test_key']);

        // 验证虚拟主题标记
        $this->assertEquals($virtualThemeId, $welineTheme->getData('virtual_theme_id'));
        $this->assertTrue($welineTheme->getData('is_virtual_theme'));

        // 清理测试数据
        $virtualTheme->delete();
    }

    /**
     * 集成测试：检查虚拟主题是否存在
     *
     * @group integration
     */
    public function testIsVirtualThemeExistsReturnsTrueForExistingTheme(): void
    {
        // 创建测试虚拟主题
        /** @var VirtualTheme $virtualTheme */
        $virtualTheme = clone ObjectManager::getInstance(VirtualTheme::class);
        $virtualTheme->clearData()->clearQuery();
        $virtualTheme->setName('Test Exists Theme')
            ->setSessionId(1)
            ->setPath('test/exists-theme')
            ->setSource(VirtualTheme::SOURCE_PAGEBUILDER_AI)
            ->setIsActive(false)
            ->save();

        $virtualThemeId = $virtualTheme->getId();
        $this->assertGreaterThan(0, $virtualThemeId);

        // 验证主题存在
        $this->assertTrue($this->resolver->isVirtualThemeExists($virtualThemeId));

        // 清理测试数据
        $virtualTheme->delete();
    }

    /**
     * 集成测试：确保虚拟主题目录结构
     *
     * @group integration
     */
    public function testEnsureVirtualThemeDirectoriesCreatesStructure(): void
    {
        $virtualThemeId = 99999; // 使用一个不太可能冲突的ID

        // 清理可能存在的目录
        $basePath = $this->resolver->getVirtualThemePath($virtualThemeId);
        if (\is_dir($basePath)) {
            $this->removeDirectory($basePath);
        }

        // 创建目录结构
        $result = $this->resolver->ensureVirtualThemeDirectories($virtualThemeId);
        $this->assertTrue($result);

        // 验证目录存在
        $this->assertDirectoryExists($basePath);
        $this->assertDirectoryExists($basePath . 'frontend' . DS);
        $this->assertDirectoryExists($basePath . 'frontend' . DS . 'layouts' . DS);
        $this->assertDirectoryExists($basePath . 'frontend' . DS . 'components' . DS);
        $this->assertDirectoryExists($basePath . 'backend' . DS);
        $this->assertDirectoryExists($basePath . 'backend' . DS . 'layouts' . DS);
        $this->assertDirectoryExists($basePath . 'backend' . DS . 'components' . DS);

        // 清理测试目录
        $this->removeDirectory($basePath);
    }

    /**
     * 递归删除目录
     */
    private function removeDirectory(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }

        $files = \array_diff(\scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DS . $file;
            \is_dir($path) ? $this->removeDirectory($path) : \unlink($path);
        }
        \rmdir($dir);
    }
}
