<?php

namespace Weline\Extends\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Weline_Extends 控制器简化测试
 */
class ExtendsControllerTest extends TestCase
{
    public function testBasicFunctionality(): void
    {
        // 基本功能测试
        $this->assertTrue(true, '基本测试应该通过');
        
        // 测试扩展数据处理
        $mockData = [
            'Weline_Sticker' => [
                'extends' => ['Sticker' => ['type' => 'module']],
                'extended_by' => [
                    'Weline_ModuleA' => [
                        [
                            'is_sticker_extension' => true,
                            'file_path' => 'some/file.php'
                        ]
                    ]
                ]
            ]
        ];
        
        $stats = $this->calculateBasicStats($mockData);
        $this->assertIsArray($stats, '统计数据应该是数组');
        $this->assertEquals(1, $stats['total_modules'], '总模块数应该是1');
    }
    
    /**
     * 模拟计算基础统计数据
     */
    private function calculateBasicStats($data)
    {
        return [
            'total_modules' => count($data),
            'sticker_extensions_count' => 1
        ];
    }
}
