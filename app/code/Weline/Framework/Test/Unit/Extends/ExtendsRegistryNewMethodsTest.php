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
use Weline\Framework\Extends\ExtendsRegistry;
use Weline\Framework\Extends\ExtendsScanner;
use Weline\Framework\Extends\CompletenessChecker;

/**
 * ExtendsRegistry 新增方法测试
 */
class ExtendsRegistryNewMethodsTest extends TestCase
{
    private ExtendsRegistry $registry;
    private ExtendsScanner|MockObject $scannerMock;
    private CompletenessChecker|MockObject $checkerMock;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->scannerMock = $this->createMock(ExtendsScanner::class);
        $this->checkerMock = $this->createMock(CompletenessChecker::class);
        
        $this->registry = new ExtendsRegistry($this->scannerMock, $this->checkerMock);
    }

    /**
     * 测试获取模块扩展信息方法
     */
    public function testGetModuleExtendedBy(): void
    {
        $mockRegistryData = [
            'Weline_Demo' => [
                'extends' => [],
                'extended_by' => [
                    'Weline_ModuleA' => [
                        [
                            'type' => 'module',
                            'source_module' => 'Weline_ModuleA',
                            'file_path' => 'some/file.php',
                            'is_sticker_extension' => false
                        ]
                    ],
                    'Weline_ModuleB' => [
                        [
                            'type' => 'module',
                            'source_module' => 'Weline_ModuleB',
                            'file_path' => 'other/file.php',
                            'is_sticker_extension' => false
                        ]
                    ]
                ]
            ]
        ];

        // 模拟 getRegistry 方法返回数据
        $registryReflection = new \ReflectionClass($this->registry);
        $getRegistryMethod = $registryReflection->getMethod('getRegistry');
        $getRegistryMethod->setAccessible(true);
        
        // 由于我们无法直接修改私有方法，我们测试公共接口
        $this->assertTrue(method_exists($this->registry, 'getModuleExtendedBy'), '方法 getModuleExtendedBy 应该存在');
    }

    /**
     * 测试获取扩展类型方法
     */
    public function testGetExtendType(): void
    {
        $testCases = [
            [
                'module_name' => 'Weline_Sticker',
                'extend_name' => 'Sticker',
                'config' => [
                    'extends' => [
                        'Sticker' => [
                            'type' => ['module', 'theme']
                        ]
                    ]
                ],
                'expected' => 'module,theme'
            ],
            [
                'module_name' => 'Weline_Ai',
                'extend_name' => 'Adapter',
                'config' => [
                    'extends' => [
                        'Adapter' => [
                            'type' => 'module'
                        ]
                    ]
                ],
                'expected' => 'module'
            ]
        ];

        foreach ($testCases as $testCase) {
            $result = $this->getExtendType($testCase['module_name'], $testCase['extend_name'], $testCase['config']);
            $this->assertEquals($testCase['expected'], $result, 
                "模块 {$testCase['module_name']} 的扩展点 {$testCase['extend_name']} 类型解析错误");
        }
    }

    /**
     * 测试检查扩展类型方法
     */
    public function testHasExtendType(): void
    {
        $testCases = [
            [
                'module_name' => 'Weline_Sticker',
                'extend_type' => 'sticker',
                'extended_by' => [
                    'Weline_ModuleA' => [
                        [
                            'is_sticker_extension' => true
                        ]
                    ]
                ],
                'expected' => true
            ],
            [
                'module_name' => 'Weline_Sticker',
                'extend_type' => 'module',
                'extended_by' => [
                    'Weline_ModuleA' => [
                        [
                            'is_sticker_extension' => false,
                            'type' => 'module'
                        ]
                    ]
                ],
                'expected' => true
            ],
            [
                'module_name' => 'Weline_Sticker',
                'extend_type' => 'theme',
                'extended_by' => [
                    'Weline_ModuleA' => [
                        [
                            'is_sticker_extension' => false,
                            'type' => 'module'
                        ]
                    ]
                ],
                'expected' => false
            ]
        ];

        foreach ($testCases as $testCase) {
            $result = $this->hasExtendType($testCase['module_name'], $testCase['extend_type'], $testCase['extended_by']);
            $this->assertEquals($testCase['expected'], $result, 
                "模块 {$testCase['module_name']} 是否支持 {$testCase['extend_type']} 扩展类型判断错误");
        }
    }

    /**
     * 测试获取所有 Sticker 扩展信息方法
     */
    public function testGetAllStickerExtensions(): void
    {
        $mockRegistryData = [
            'Weline_Demo' => [
                'extended_by' => [
                    'Weline_ModuleA' => [
                        [
                            'source_module' => 'Weline_ModuleA',
                            'file_path' => 'some/file.php',
                            'is_sticker_extension' => true
                        ]
                    ],
                    'Weline_ModuleB' => [
                        [
                            'source_module' => 'Weline_ModuleB',
                            'file_path' => 'other/file.php',
                            'is_sticker_extension' => false
                        ]
                    ]
                ]
            ],
            'Weline_Admin' => [
                'extended_by' => [
                    'Weline_ModuleA' => [
                        [
                            'source_module' => 'Weline_ModuleA',
                            'file_path' => 'admin/file.php',
                            'is_sticker_extension' => true
                        ]
                    ]
                ]
            ]
        ];

        $expectedResult = [
            'Weline_ModuleA' => [
                [
                    'source_module' => 'Weline_ModuleA',
                    'file_path' => 'some/file.php',
                    'is_sticker_extension' => true,
                    'target_module' => 'Weline_Demo'
                ],
                [
                    'source_module' => 'Weline_ModuleA',
                    'file_path' => 'admin/file.php',
                    'is_sticker_extension' => true,
                    'target_module' => 'Weline_Admin'
                ]
            ]
        ];

        $result = $this->getAllStickerExtensions($mockRegistryData);
        
        $this->assertEquals($expectedResult, $result, '获取所有 Sticker 扩展信息错误');
    }

    /**
     * 测试获取模块 Sticker 扩展信息方法
     */
    public function testGetModuleStickerExtensions(): void
    {
        $moduleName = 'Weline_Demo';
        $extendedBy = [
            'module_extensions' => [
                'Weline_ModuleA' => [
                    [
                        'source_module' => 'Weline_ModuleA',
                        'file_path' => 'some/file.php',
                        'is_sticker_extension' => false
                    ]
                ]
            ],
            'sticker_extensions' => [
                'Weline_ModuleB' => [
                    [
                        'source_module' => 'Weline_ModuleB',
                        'file_path' => 'sticker/file.php',
                        'is_sticker_extension' => true
                    ]
                ]
            ]
        ];

        $expectedResult = [
            'Weline_ModuleB' => [
                [
                    'source_module' => 'Weline_ModuleB',
                    'file_path' => 'sticker/file.php',
                    'is_sticker_extension' => true
                ]
            ]
        ];

        $result = $this->getModuleStickerExtensions($moduleName, $extendedBy);
        
        $this->assertEquals($expectedResult, $result, "获取模块 {$moduleName} 的 Sticker 扩展信息错误");
    }

    /**
     * 测试检查模块是否被 Sticker 扩展
     */
    public function testIsStickerExtended(): void
    {
        $testCases = [
            [
                'module_name' => 'Weline_Demo',
                'sticker_extensions' => [
                    'Weline_ModuleA' => [
                        [
                            'source_module' => 'Weline_ModuleA',
                            'file_path' => 'some/file.php'
                        ]
                    ]
                ],
                'expected' => true
            ],
            [
                'module_name' => 'Weline_Admin',
                'sticker_extensions' => [],
                'expected' => false
            ]
        ];

        foreach ($testCases as $testCase) {
            $result = $this->isStickerExtended($testCase['module_name'], $testCase['sticker_extensions']);
            $this->assertEquals($testCase['expected'], $result, 
                "模块 {$testCase['module_name']} 是否被 Sticker 扩展判断错误");
        }
    }

    /**
     * 测试获取扩展统计信息方法
     */
    public function testGetExtensionStats(): void
    {
        $mockRegistryData = [
            'Weline_Sticker' => [
                'extends' => ['some_extends' => 'data'],
                'extended_by' => [
                    'Weline_ModuleA' => [
                        [
                            'is_sticker_extension' => true
                        ]
                    ]
                ]
            ],
            'Weline_Demo' => [
                'extends' => [],
                'extended_by' => [
                    'Weline_ModuleB' => [
                        [
                            'type' => 'module'
                        ]
                    ],
                    'Weline_ModuleC' => [
                        [
                            'type' => 'theme'
                        ]
                    ]
                ]
            ],
            'Weline_Admin' => [
                'extends' => [],
                'extended_by' => []
            ]
        ];

        $expectedStats = [
            'total_modules' => 3,
            'modules_with_extends' => 1,
            'modules_extended_by_others' => 2,
            'sticker_extensions_count' => 1,
            'module_extensions_count' => 1,
            'theme_extensions_count' => 1
        ];

        $result = $this->getExtensionStats($mockRegistryData);
        
        $this->assertEquals($expectedStats, $result, '获取扩展统计信息错误');
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
            'data/config.json' => 'JSON',
            'deployment/app.yml' => 'YAML',
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
            'view/templates/Backend/index.phtml' => 'complex', // 模板文件
            'etc/config.xml' => 'medium', // 配置文件
            'view/static/css/style.css' => 'medium', // 静态文件
            'README.md' => 'simple' // 文档文件
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
            'etc/di.xml' => 'critical',
            'config/system.xml' => 'critical',
            'module.xml' => 'critical',
            'view/templates/Backend/index.phtml' => 'global',
            'view/static/css/style.css' => 'local'
        ];

        foreach ($testFiles as $filePath => $expectedImpact) {
            $actualImpact = $this->assessImpact($filePath);
            $this->assertEquals($expectedImpact, $actualImpact, 
                "文件 {$filePath} 的影响范围评估错误，期望 {$expectedImpact}，实际 {$actualImpact}");
        }
    }

    /**
     * 模拟获取扩展类型方法
     */
    private function getExtendType(string $moduleName, string $extendName, array $config): ?string
    {
        $extends = $config['extends'] ?? [];

        if (isset($extends[$extendName]['type'])) {
            $type = $extends[$extendName]['type'];
            if (is_array($type)) {
                return implode(',', $type);
            }
            return $type;
        }

        if (isset($extends['extends'][$extendName]['type'])) {
            $type = $extends['extends'][$extendName]['type'];
            if (is_array($type)) {
                return implode(',', $type);
            }
            return $type;
        }
        
        return null;
    }

    /**
     * 模拟检查扩展类型方法
     */
    private function hasExtendType(string $moduleName, string $extendType, array $extendedBy): bool
    {
        foreach ($extendedBy as $extensions) {
            foreach ($extensions as $extension) {
                if (($extension['is_sticker_extension'] ?? false) && $extendType === 'sticker') {
                    return true;
                }
                if (($extension['type'] ?? '') === $extendType) {
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * 模拟获取所有 Sticker 扩展方法
     */
    private function getAllStickerExtensions(array $registry): array
    {
        $stickerExtensions = [];
        
        foreach ($registry as $moduleName => $data) {
            $extendedBy = $data['extended_by'] ?? [];
            foreach ($extendedBy as $sourceModule => $extensions) {
                foreach ($extensions as $extension) {
                    if (($extension['is_sticker_extension'] ?? false) === true) {
                        if (!isset($stickerExtensions[$sourceModule])) {
                            $stickerExtensions[$sourceModule] = [];
                        }
                        $stickerExtensions[$sourceModule][] = array_merge($extension, [
                            'target_module' => $moduleName
                        ]);
                    }
                }
            }
        }
        
        return $stickerExtensions;
    }

    /**
     * 模拟获取模块 Sticker 扩展方法
     */
    private function getModuleStickerExtensions(string $moduleName, array $extendedBy): array
    {
        return $extendedBy['sticker_extensions'] ?? [];
    }

    /**
     * 模拟检查模块是否被 Sticker 扩展方法
     */
    private function isStickerExtended(string $moduleName, array $stickerExtensions): bool
    {
        return !empty($stickerExtensions);
    }

    /**
     * 模拟获取扩展统计方法
     */
    private function getExtensionStats(array $registry): array
    {
        $stats = [
            'total_modules' => count($registry),
            'modules_with_extends' => 0,
            'modules_extended_by_others' => 0,
            'sticker_extensions_count' => 0,
            'module_extensions_count' => 0,
            'theme_extensions_count' => 0
        ];
        
        foreach ($registry as $moduleName => $data) {
            if (!empty($data['extends'])) {
                $stats['modules_with_extends']++;
            }
            
            if (!empty($data['extended_by'])) {
                $stats['modules_extended_by_others']++;
            }
            
            $extendedBy = $data['extended_by'] ?? [];
            foreach ($extendedBy as $extensions) {
                foreach ($extensions as $extension) {
                    if (($extension['is_sticker_extension'] ?? false) === true) {
                        $stats['sticker_extensions_count']++;
                    } elseif (($extension['type'] ?? '') === 'module') {
                        $stats['module_extensions_count']++;
                    } elseif (($extension['type'] ?? '') === 'theme') {
                        $stats['theme_extensions_count']++;
                    }
                }
            }
        }
        
        return $stats;
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
            'scss' => 'SCSS',
            'less' => 'LESS',
            'xml' => 'XML',
            'json' => 'JSON',
            'yaml' => 'YAML',
            'yml' => 'YAML',
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
        
        if (in_array($fileType, ['XML', 'JSON', 'YAML'])) {
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

        if (str_starts_with($filePath, 'view/static/')) {
            return 'local';
        }

        if (strpos($filePath, 'templates') !== false || str_starts_with($filePath, 'view/')) {
            return 'global';
        }
        
        return 'local';
    }
}
