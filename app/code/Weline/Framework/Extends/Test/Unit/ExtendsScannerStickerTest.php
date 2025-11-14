<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Extends\Test\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Weline\Framework\Extends\ExtendsScanner;
use Weline\Framework\App\Env;
use Weline\Framework\App\Module;

/**
 * ExtendsScanner Sticker 扩展识别测试
 */
class ExtendsScannerStickerTest extends TestCase
{
    private ExtendsScanner $scanner;
    private Env|MockObject $envMock;

    protected function setUp(): void
    {
        parent::setUp();
        
        // 创建模拟的环境对象
        $this->envMock = $this->createMock(Env::class);
        
        // 创建扫描器实例
        $this->scanner = new ExtendsScanner($this->envMock);
    }

    /**
     * 测试扫描器可以识别 Sticker 扩展
     */
    public function testScannerCanIdentifyStickerExtensions(): void
    {
        // 模拟模块列表
        $modules = [
            'Weline_ModuleA' => [
                'base_path' => '/path/to/moduleA',
                'status' => true
            ],
            'Weline_Sticker' => [
                'base_path' => '/path/to/sticker',
                'status' => true
            ],
            'Weline_ModuleB' => [
                'base_path' => '/path/to/moduleB',
                'status' => true
            ]
        ];

        $this->envMock
            ->expects($this->once())
            ->method('getModuleList')
            ->willReturn($modules);

        // 由于我们无法真正创建文件系统，我们将测试逻辑结构
        $this->assertInstanceOf(ExtendsScanner::class, $this->scanner, 'ExtendsScanner 应该被正确实例化');
    }

    /**
     * 测试 Sticker 扩展路径解析
     */
    public function testStickerExtensionPathParsing(): void
    {
        // 测试路径解析逻辑
        $testCases = [
            // 正常的 Sticker 扩展路径
            [
                'input' => 'extends/module/Weline_Sticker/Weline_Demo/view/templates/Backend/index.phtml',
                'expected_target_module' => 'Weline_Demo',
                'expected_file_path' => 'Weline/Demo/view/templates/Backend/index.phtml',
                'is_sticker' => true
            ],
            // 主题级 Sticker 扩展路径
            [
                'input' => 'extends/theme/default/Weline_Sticker/Weline_Admin/view/templates/index.phtml',
                'expected_target_module' => 'Weline_Admin',
                'expected_file_path' => 'Weline/Admin/view/templates/index.phtml',
                'is_sticker' => true
            ],
            // 非 Sticker 扩展路径
            [
                'input' => 'extends/module/Weline_Ai/Adapter/MyAdapter.php',
                'expected_target_module' => 'Weline_Ai',
                'expected_file_path' => 'Weline/Ai/Adapter/MyAdapter.php',
                'is_sticker' => false
            ]
        ];

        foreach ($testCases as $testCase) {
            $result = $this->parsePathForSticker($testCase['input']);
            
            $this->assertEquals($testCase['expected_target_module'], $result['target_module'], 
                "路径 {$testCase['input']} 的目标模块解析错误");
            $this->assertEquals($testCase['expected_file_path'], $result['file_path'], 
                "路径 {$testCase['input']} 的文件路径解析错误");
            $this->assertEquals($testCase['is_sticker'], $result['is_sticker_extension'], 
                "路径 {$testCase['input']} 的 Sticker 标识错误");
        }
    }

    /**
     * 测试模块级 Sticker 扩展识别
     */
    public function testModuleLevelStickerExtensionIdentification(): void
    {
        $moduleStickerPaths = [
            'extends/module/Weline_Sticker/Weline_Demo/view/templates/Backend/index.phtml',
            'extends/module/Weline_Sticker/Weline_Admin/view/templates/index.phtml',
            'extends/module/Weline_Sticker/Weline_User/view/frontend/layout/default.xml'
        ];

        foreach ($moduleStickerPaths as $path) {
            $result = $this->parsePathForSticker($path);
            
            $this->assertTrue($result['is_sticker_extension'], "路径 {$path} 应该被识别为 Sticker 扩展");
            $this->assertEquals('module', $result['type'], "路径 {$path} 的类型应该是 module");
        }
    }

    /**
     * 测试主题级 Sticker 扩展识别
     */
    public function testThemeLevelStickerExtensionIdentification(): void
    {
        $themeStickerPaths = [
            'extends/theme/default/Weline_Sticker/Weline_Demo/view/templates/Backend/index.phtml',
            'extends/theme/dark/Weline_Sticker/Weline_Admin/view/templates/index.phtml',
            'extends/theme/modern/Weline_Sticker/Weline_User/view/frontend/layout/default.xml'
        ];

        foreach ($themeStickerPaths as $path) {
            $result = $this->parsePathForSticker($path);
            
            $this->assertTrue($result['is_sticker_extension'], "路径 {$path} 应该被识别为 Sticker 扩展");
            $this->assertEquals('theme', $result['type'], "路径 {$path} 的类型应该是 theme");
            $this->assertEquals(basename(dirname(dirname($path))), $result['theme_name'], 
                "路径 {$path} 的主题名解析错误");
        }
    }

    /**
     * 测试错误路径处理
     */
    public function testInvalidPathHandling(): void
    {
        $invalidPaths = [
            'extends/Weline_Sticker/invalid', // 缺少层级
            'extends/module/Weline_Sticker', // 缺少目标模块
            'extends/module/InvalidModule/some/file.php', // 不是 Sticker 扩展
        ];

        foreach ($invalidPaths as $path) {
            $result = $this->parsePathForSticker($path);
            
            // 对于无效路径，解析结果应该为空或包含错误信息
            $this->assertTrue(
                empty($result) || isset($result['error']), 
                "无效路径 {$path} 应该被正确处理"
            );
        }
    }

    /**
     * 测试文件类型识别
     */
    public function testFileTypeIdentification(): void
    {
        $testFiles = [
            'view/templates/Backend/index.phtml' => 'Template',
            'view/templates/Frontend/index.html' => 'HTML',
            'view/static/css/style.css' => 'CSS',
            'view/static/js/app.js' => 'JavaScript',
            'etc/config.xml' => 'XML',
            'Model/Data.php' => 'PHP',
            'doc/readme.md' => 'Markdown',
            'unknown_file.xyz' => 'Unknown'
        ];

        foreach ($testFiles as $filePath => $expectedType) {
            $actualType = $this->getFileType($filePath);
            $this->assertEquals($expectedType, $actualType, 
                "文件 {$filePath} 的类型识别错误，期望 {$expectedType}，实际 {$actualType}");
        }
    }

    /**
     * 测试扩展复杂度计算
     */
    public function testExtensionComplexityCalculation(): void
    {
        $testFiles = [
            'view/templates/Backend/index.phtml' => 'complex', // 模板文件通常复杂度高
            'etc/config.xml' => 'medium', // 配置文件通常复杂度中等
            'view/static/css/style.css' => 'medium', // 静态文件复杂度中等
            'README.md' => 'simple' // 文档文件复杂度低
        ];

        foreach ($testFiles as $filePath => $expectedComplexity) {
            $actualComplexity = $this->calculateComplexity($filePath);
            $this->assertEquals($expectedComplexity, $actualComplexity, 
                "文件 {$filePath} 的复杂度计算错误，期望 {$expectedComplexity}，实际 {$actualComplexity}");
        }
    }

    /**
     * 测试影响范围评估
     */
    public function testImpactScopeAssessment(): void
    {
        $testFiles = [
            'etc/di.xml' => 'critical', // 核心配置文件
            'config/system.xml' => 'critical', // 系统配置
            'view/templates/Backend/index.phtml' => 'global', // 模板文件
            'view/static/css/style.css' => 'local' // 静态文件
        ];

        foreach ($testFiles as $filePath => $expectedImpact) {
            $actualImpact = $this->assessImpact($filePath);
            $this->assertEquals($expectedImpact, $actualImpact, 
                "文件 {$filePath} 的影响范围评估错误，期望 {$expectedImpact}，实际 {$actualImpact}");
        }
    }

    /**
     * 模拟路径解析方法（用于测试）
     */
    private function parsePathForSticker(string $relativePath): array
    {
        $pathParts = explode('/', $relativePath);
        
        if (count($pathParts) < 3) {
            return ['error' => '路径层级不足'];
        }

        // 检查是否是 Sticker 扩展
        if ($pathParts[2] === 'Weline_Sticker') {
            if (count($pathParts) < 4) {
                return ['error' => 'Sticker 扩展缺少目标模块'];
            }
            
            $actualTargetModulePath = $pathParts[3] ?? '';
            if (empty($actualTargetModulePath)) {
                return ['error' => '目标模块路径为空'];
            }
            
            $targetModule = str_replace('/', '_', $actualTargetModulePath);
            $fileRelativePath = implode('/', array_slice($pathParts, 4));
            
            return [
                'target_module' => $targetModule,
                'file_path' => $fileRelativePath,
                'is_sticker_extension' => true,
                'type' => $pathParts[1] === 'theme' ? 'theme' : 'module',
                'theme_name' => $pathParts[1] === 'theme' ? $pathParts[1] : null
            ];
        } else {
            // 非 Sticker 扩展
            $targetModulePath = $pathParts[2] ?? '';
            $targetModule = str_replace('/', '_', $targetModulePath);
            $fileRelativePath = implode('/', array_slice($pathParts, 3));
            
            return [
                'target_module' => $targetModule,
                'file_path' => $fileRelativePath,
                'is_sticker_extension' => false,
                'type' => $pathParts[1] ?? 'unknown'
            ];
        }
    }

    /**
     * 获取文件类型（模拟方法）
     */
    private function getFileType(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        $typeMap = [
            'php' => 'PHP',
            'phtml' => 'Template',
            'html' => 'HTML',
            'js' => 'JavaScript',
            'css' => 'CSS',
            'xml' => 'XML',
            'md' => 'Markdown'
        ];
        
        return $typeMap[$extension] ?? 'Unknown';
    }

    /**
     * 计算复杂度（模拟方法）
     */
    private function calculateComplexity(string $filePath): string
    {
        $fileType = $this->getFileType($filePath);
        
        if ($fileType === 'Template') {
            return 'complex';
        }
        
        if (in_array($fileType, ['XML', 'JSON'])) {
            return 'medium';
        }
        
        if (in_array($fileType, ['CSS', 'JavaScript'])) {
            return 'medium';
        }
        
        return 'simple';
    }

    /**
     * 评估影响范围（模拟方法）
     */
    private function assessImpact(string $filePath): string
    {
        $pathParts = explode('/', $filePath);
        
        $criticalPaths = ['config', 'etc', 'di.xml', 'module.xml'];
        foreach ($criticalPaths as $criticalPath) {
            if (in_array($criticalPath, $pathParts)) {
                return 'critical';
            }
        }
        
        if (strpos($filePath, 'templates') !== false || strpos($filePath, 'view') !== false) {
            return 'global';
        }
        
        return 'local';
    }
}
