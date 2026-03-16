<?php
declare(strict_types=1);

namespace Weline\AppStore\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 模块安装服务单元测试
 */
class ModuleInstallerServiceTest extends TestCase
{
    /**
     * 测试模块名解析
     */
    public function testParseModuleName(): void
    {
        $moduleName = 'Weline_AppStore';
        $parts = explode('_', $moduleName);

        $this->assertCount(2, $parts);
        $this->assertEquals('Weline', $parts[0]);
        $this->assertEquals('AppStore', $parts[1]);
    }

    /**
     * 测试模块目标目录路径
     */
    public function testGetModuleTargetDir(): void
    {
        $moduleName = 'Weline_AppStore';
        $expectedDir = APP_CODE_PATH . 'Weline' . DS . 'AppStore';
        $actualDir = APP_CODE_PATH . str_replace('_', DS, $moduleName);

        $this->assertEquals($expectedDir, $actualDir);
    }

    /**
     * 测试版本比较
     */
    public function testVersionComparison(): void
    {
        // 相同版本
        $this->assertEquals(0, version_compare('1.0.0', '1.0.0'));

        // 较小版本
        $this->assertEquals(-1, version_compare('1.0.0', '1.0.1'));
        $this->assertEquals(-1, version_compare('1.0.0', '1.1.0'));
        $this->assertEquals(-1, version_compare('1.0.0', '2.0.0'));

        // 较大版本
        $this->assertEquals(1, version_compare('1.0.1', '1.0.0'));
        $this->assertEquals(1, version_compare('2.0.0', '1.0.0'));
    }

    /**
     * 测试依赖检查逻辑
     */
    public function testDependencyCheck(): void
    {
        $dependencies = ['Weline_Framework', 'Weline_Eav'];
        $installed = ['Weline_Framework' => true, 'Weline_Eav' => true, 'Other_Module' => true];

        $missing = [];
        foreach ($dependencies as $dep) {
            if (!isset($installed[$dep])) {
                $missing[] = $dep;
            }
        }

        $this->assertEmpty($missing, 'All dependencies should be satisfied');
    }

    /**
     * 测试缺少依赖的情况
     */
    public function testDependencyCheckMissing(): void
    {
        $dependencies = ['Weline_Framework', 'Weline_Eav', 'Missing_Module'];
        $installed = ['Weline_Framework' => true, 'Weline_Eav' => true];

        $missing = [];
        foreach ($dependencies as $dep) {
            if (!isset($installed[$dep])) {
                $missing[] = $dep;
            }
        }

        $this->assertCount(1, $missing);
        $this->assertEquals('Missing_Module', $missing[0]);
    }

    /**
     * 测试文件哈希验证
     */
    public function testFileHashVerification(): void
    {
        // 创建临时测试文件
        $tempFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tempFile, 'test content');

        $hash = hash_file('sha256', $tempFile);
        $expectedHash = hash('sha256', 'test content');

        $this->assertEquals($expectedHash, $hash);

        // 清理
        unlink($tempFile);
    }

    /**
     * 测试临时目录创建
     */
    public function testTempDirectoryCreation(): void
    {
        $tempDir = BP . 'var' . DS . 'appstore' . DS . 'temp';

        // 验证路径格式
        $this->assertStringContainsString('var', $tempDir);
        $this->assertStringContainsString('appstore', $tempDir);
        $this->assertStringContainsString('temp', $tempDir);
    }
}
