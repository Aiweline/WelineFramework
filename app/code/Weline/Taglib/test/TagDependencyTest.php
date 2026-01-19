<?php

namespace Weline\Taglib\test;

use Weline\Framework\UnitTest\TestCore;
use Weline\Taglib\Observer\TagParser;
use Weline\Framework\Event\Event;
use Weline\Framework\DataObject\DataObject;

/**
 * 标签依赖管理测试类
 */
class TagDependencyTest extends TestCore
{
    private TagParser $tagParser;

    public function setUp(): void
    {
        parent::setUp();
        $this->tagParser = new TagParser();
    }

    /**
     * 测试依赖排序功能
     */
    public function testDependencySorting()
    {
        // 模拟标签数据
        $tags = [
            'child-tag' => [
                'parent' => 'parent-tag',
                'name' => 'child-tag',
                'class' => 'Test\ChildTag'
            ],
            'parent-tag' => [
                'name' => 'parent-tag',
                'class' => 'Test\ParentTag'
            ],
            'independent-tag' => [
                'name' => 'independent-tag',
                'class' => 'Test\IndependentTag'
            ]
        ];

        // 使用反射调用私有方法
        $reflection = new \ReflectionClass($this->tagParser);
        $method = $reflection->getMethod('sortTagsByDependencies');
        $method->setAccessible(true);

        $sortedTags = $method->invoke($this->tagParser, $tags);

        // 验证排序结果：父标签应该在子标签之前
        $tagNames = array_keys($sortedTags);
        $parentIndex = array_search('parent-tag', $tagNames);
        $childIndex = array_search('child-tag', $tagNames);

        $this->assertLessThan($childIndex, $parentIndex, '父标签应该在子标签之前');
    }

    /**
     * 测试循环依赖检测
     */
    public function testCircularDependencyDetection()
    {
        // 模拟循环依赖
        $tags = [
            'tag-a' => [
                'parent' => 'tag-b',
                'name' => 'tag-a'
            ],
            'tag-b' => [
                'parent' => 'tag-c',
                'name' => 'tag-b'
            ],
            'tag-c' => [
                'parent' => 'tag-a',
                'name' => 'tag-c'
            ]
        ];

        $reflection = new \ReflectionClass($this->tagParser);
        $method = $reflection->getMethod('sortTagsByDependencies');
        $method->setAccessible(true);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('检测到标签循环依赖');
        
        $method->invoke($this->tagParser, $tags);
    }

    /**
     * 测试依赖验证功能
     */
    public function testDependencyValidation()
    {
        // 模拟无效依赖
        $tags = [
            'child-tag' => [
                'parent' => 'non-existent-parent',
                'name' => 'child-tag'
            ]
        ];

        $reflection = new \ReflectionClass($this->tagParser);
        $method = $reflection->getMethod('validateDependencies');
        $method->setAccessible(true);

        $result = $method->invoke($this->tagParser, $tags);

        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('不存在', $result['errors'][0]);
    }

    /**
     * 测试复杂依赖链
     */
    public function testComplexDependencyChain()
    {
        // 模拟复杂的依赖链：grandchild -> child -> parent
        $tags = [
            'grandchild-tag' => [
                'parent' => 'child-tag',
                'name' => 'grandchild-tag'
            ],
            'child-tag' => [
                'parent' => 'parent-tag',
                'name' => 'child-tag'
            ],
            'parent-tag' => [
                'name' => 'parent-tag'
            ],
            'independent-tag' => [
                'name' => 'independent-tag'
            ]
        ];

        $reflection = new \ReflectionClass($this->tagParser);
        $method = $reflection->getMethod('sortTagsByDependencies');
        $method->setAccessible(true);

        $sortedTags = $method->invoke($this->tagParser, $tags);
        $tagNames = array_keys($sortedTags);

        // 验证依赖顺序
        $parentIndex = array_search('parent-tag', $tagNames);
        $childIndex = array_search('child-tag', $tagNames);
        $grandchildIndex = array_search('grandchild-tag', $tagNames);

        $this->assertLessThan($childIndex, $parentIndex, 'parent应该在child之前');
        $this->assertLessThan($grandchildIndex, $childIndex, 'child应该在grandchild之前');
    }

    /**
     * 测试无依赖标签
     */
    public function testNoDependencies()
    {
        // 模拟没有依赖的标签
        $tags = [
            'tag-1' => ['name' => 'tag-1'],
            'tag-2' => ['name' => 'tag-2'],
            'tag-3' => ['name' => 'tag-3']
        ];

        $reflection = new \ReflectionClass($this->tagParser);
        $method = $reflection->getMethod('sortTagsByDependencies');
        $method->setAccessible(true);

        $sortedTags = $method->invoke($this->tagParser, $tags);

        // 没有依赖时，顺序应该保持不变
        $this->assertEquals($tags, $sortedTags);
    }

    /**
     * 测试混合依赖场景
     */
    public function testMixedDependencies()
    {
        // 模拟混合依赖场景
        $tags = [
            'independent-1' => ['name' => 'independent-1'],
            'child-1' => ['parent' => 'parent-1', 'name' => 'child-1'],
            'parent-1' => ['name' => 'parent-1'],
            'independent-2' => ['name' => 'independent-2'],
            'child-2' => ['parent' => 'parent-2', 'name' => 'child-2'],
            'parent-2' => ['name' => 'parent-2']
        ];

        $reflection = new \ReflectionClass($this->tagParser);
        $method = $reflection->getMethod('sortTagsByDependencies');
        $method->setAccessible(true);

        $sortedTags = $method->invoke($this->tagParser, $tags);
        $tagNames = array_keys($sortedTags);

        // 验证所有父标签都在对应的子标签之前
        $parent1Index = array_search('parent-1', $tagNames);
        $child1Index = array_search('child-1', $tagNames);
        $parent2Index = array_search('parent-2', $tagNames);
        $child2Index = array_search('child-2', $tagNames);

        $this->assertLessThan($child1Index, $parent1Index);
        $this->assertLessThan($child2Index, $parent2Index);
    }
} 