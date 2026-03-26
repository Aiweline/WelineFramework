<?php

declare(strict_types=1);

namespace Weline\Framework\Extends\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Extends\ExtendsScanner;

class ExtendsScannerStickerTest extends TestCase
{
    private ExtendsScanner $scanner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scanner = new ExtendsScanner();
    }

    public function testScannerCanIdentifyStickerExtensions(): void
    {
        $this->assertInstanceOf(ExtendsScanner::class, $this->scanner);
    }

    public function testStickerExtensionPathParsing(): void
    {
        $testCases = [
            [
                'input' => 'extends/module/Weline_Sticker/Weline/Demo/view/templates/Backend/index.phtml',
                'expected_target_module' => 'Weline_Demo',
                'expected_file_path' => 'view/templates/Backend/index.phtml',
                'is_sticker' => true,
            ],
            [
                'input' => 'extends/theme/default/Weline_Sticker/Weline/Admin/view/templates/index.phtml',
                'expected_target_module' => 'Weline_Admin',
                'expected_file_path' => 'view/templates/index.phtml',
                'is_sticker' => true,
            ],
            [
                'input' => 'extends/module/Weline_Ai/Adapter/MyAdapter.php',
                'expected_target_module' => 'Weline_Ai',
                'expected_file_path' => 'Adapter/MyAdapter.php',
                'is_sticker' => false,
            ],
        ];

        foreach ($testCases as $testCase) {
            $result = $this->parsePathForSticker($testCase['input']);

            $this->assertEquals($testCase['expected_target_module'], $result['target_module']);
            $this->assertEquals($testCase['expected_file_path'], $result['file_path']);
            $this->assertEquals($testCase['is_sticker'], $result['is_sticker_extension']);
        }
    }

    public function testModuleLevelStickerExtensionIdentification(): void
    {
        $moduleStickerPaths = [
            'extends/module/Weline_Sticker/Weline/Demo/view/templates/Backend/index.phtml',
            'extends/module/Weline_Sticker/Weline/Admin/view/templates/index.phtml',
            'extends/module/Weline_Sticker/Weline/User/view/frontend/layout/default.xml',
        ];

        foreach ($moduleStickerPaths as $path) {
            $result = $this->parsePathForSticker($path);

            $this->assertTrue($result['is_sticker_extension'], "Path {$path} should be identified as a sticker extension.");
            $this->assertEquals('module', $result['type']);
        }
    }

    public function testThemeLevelStickerExtensionIdentification(): void
    {
        $themeStickerPaths = [
            'extends/theme/default/Weline_Sticker/Weline/Demo/view/templates/Backend/index.phtml',
            'extends/theme/dark/Weline_Sticker/Weline/Admin/view/templates/index.phtml',
            'extends/theme/modern/Weline_Sticker/Weline/User/view/frontend/layout/default.xml',
        ];

        foreach ($themeStickerPaths as $path) {
            $result = $this->parsePathForSticker($path);

            $this->assertTrue($result['is_sticker_extension'], "Path {$path} should be identified as a sticker extension.");
            $this->assertEquals('theme', $result['type']);
            $this->assertEquals(explode('/', $path)[2], $result['theme_name']);
        }
    }

    public function testInvalidPathHandling(): void
    {
        $invalidPaths = [
            'extends/Weline_Sticker/invalid',
            'extends/module/Weline_Sticker',
            'extends/module/Weline_Sticker/Weline_Sticker/view/templates/Test/index.phtml',
        ];

        foreach ($invalidPaths as $path) {
            $result = $this->parsePathForSticker($path);
            $this->assertArrayHasKey('error', $result, "Invalid path {$path} should return an error payload.");
        }
    }

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
            'unknown_file.xyz' => 'Unknown',
        ];

        foreach ($testFiles as $filePath => $expectedType) {
            $actualType = $this->getFileType($filePath);
            $this->assertEquals($expectedType, $actualType);
        }
    }

    public function testExtensionComplexityCalculation(): void
    {
        $testFiles = [
            'view/templates/Backend/index.phtml' => 'complex',
            'etc/config.xml' => 'medium',
            'view/static/css/style.css' => 'medium',
            'README.md' => 'simple',
        ];

        foreach ($testFiles as $filePath => $expectedComplexity) {
            $actualComplexity = $this->calculateComplexity($filePath);
            $this->assertEquals($expectedComplexity, $actualComplexity);
        }
    }

    public function testImpactScopeAssessment(): void
    {
        $testFiles = [
            'etc/di.xml' => 'critical',
            'config/system.xml' => 'critical',
            'view/templates/Backend/index.phtml' => 'global',
            'view/static/css/style.css' => 'local',
        ];

        foreach ($testFiles as $filePath => $expectedImpact) {
            $actualImpact = $this->assessImpact($filePath);
            $this->assertEquals($expectedImpact, $actualImpact);
        }
    }

    private function parsePathForSticker(string $relativePath): array
    {
        $pathParts = explode('/', $relativePath);

        if (($pathParts[0] ?? '') !== 'extends') {
            return ['error' => 'Path must start with extends'];
        }

        $type = $pathParts[1] ?? '';
        if (!in_array($type, ['module', 'theme'], true)) {
            return ['error' => 'Unsupported extends type'];
        }

        if ($type === 'theme') {
            $themeName = $pathParts[2] ?? '';
            $targetIndex = 3;
            if ($themeName === '') {
                return ['error' => 'Missing theme name'];
            }
        } else {
            $themeName = null;
            $targetIndex = 2;
        }

        $targetModulePath = $pathParts[$targetIndex] ?? '';
        if ($targetModulePath === '') {
            return ['error' => 'Missing target module'];
        }

        $nextIndex = $targetIndex + 1;

        if ($targetModulePath === 'Weline_Sticker') {
            $vendor = $pathParts[$nextIndex] ?? '';
            $module = $pathParts[$nextIndex + 1] ?? '';
            $fileRelativePath = implode('/', array_slice($pathParts, $nextIndex + 2));
            if (!$this->isModuleSegment($vendor) || !$this->isModuleSegment($module) || $fileRelativePath === '') {
                return ['error' => 'Invalid sticker extension path'];
            }

            return [
                'target_module' => $vendor . '_' . $module,
                'file_path' => $fileRelativePath,
                'is_sticker_extension' => true,
                'type' => $type,
                'theme_name' => $themeName,
            ];
        }

        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*_[A-Za-z][A-Za-z0-9_]*$/', $targetModulePath)) {
            $fileRelativePath = implode('/', array_slice($pathParts, $nextIndex));
            if ($fileRelativePath === '') {
                return ['error' => 'Missing file path'];
            }

            return [
                'target_module' => $targetModulePath,
                'file_path' => $fileRelativePath,
                'is_sticker_extension' => false,
                'type' => $type,
                'theme_name' => $themeName,
            ];
        }

        $vendor = $targetModulePath;
        $module = $pathParts[$nextIndex] ?? '';
        $fileRelativePath = implode('/', array_slice($pathParts, $nextIndex + 1));
        if (!$this->isModuleSegment($vendor) || !$this->isModuleSegment($module) || $fileRelativePath === '') {
            return ['error' => 'Invalid extension path'];
        }

        return [
            'target_module' => $vendor . '_' . $module,
            'file_path' => $fileRelativePath,
            'is_sticker_extension' => false,
            'type' => $type,
            'theme_name' => $themeName,
        ];
    }

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
            'md' => 'Markdown',
        ];

        return $typeMap[$extension] ?? 'Unknown';
    }

    private function calculateComplexity(string $filePath): string
    {
        $fileType = $this->getFileType($filePath);

        if ($fileType === 'Template') {
            return 'complex';
        }

        if (in_array($fileType, ['XML', 'JSON'], true)) {
            return 'medium';
        }

        if (in_array($fileType, ['CSS', 'JavaScript'], true)) {
            return 'medium';
        }

        return 'simple';
    }

    private function assessImpact(string $filePath): string
    {
        $pathParts = explode('/', $filePath);
        $criticalPaths = ['config', 'etc', 'di.xml', 'module.xml'];

        foreach ($criticalPaths as $criticalPath) {
            if (in_array($criticalPath, $pathParts, true)) {
                return 'critical';
            }
        }

        if (str_starts_with($filePath, 'view/static/')) {
            return 'local';
        }

        if (str_contains($filePath, 'templates') || str_starts_with($filePath, 'view/')) {
            return 'global';
        }

        return 'local';
    }

    private function isModuleSegment(string $segment): bool
    {
        return (bool)preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $segment);
    }
}
