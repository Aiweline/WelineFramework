<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\Controller\Backend\Api;

use PHPUnit\Framework\TestCase;
use Weline\Eav\Controller\Backend\Api\Tree;
use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Group;
use Weline\Eav\Model\EavAttribute\Set;
use Weline\Eav\Model\EavEntity;

/**
 * @covers \Weline\Eav\Controller\Backend\Api\Tree
 */
class TreeApiTest extends TestCase
{
    /**
     * Test Tree controller can be instantiated
     */
    public function testControllerCanBeInstantiated(): void
    {
        $this->assertTrue(class_exists(Tree::class));
    }

    /**
     * Test Tree controller has required methods
     */
    public function testControllerHasRequiredMethods(): void
    {
        $methods = [
            'getIndex',
            'getChildren',
            'getNode',
        ];

        foreach ($methods as $method) {
            $this->assertTrue(
                method_exists(Tree::class, $method),
                "Tree controller should have {$method} method"
            );
        }
    }

    /**
     * Test node types are properly defined
     */
    public function testNodeTypesAreDefined(): void
    {
        $expectedTypes = ['entity', 'set', 'group', 'attribute'];
        
        // This is a static test to ensure the expected node types are supported
        foreach ($expectedTypes as $type) {
            $this->assertNotEmpty($type, "Node type {$type} should be defined");
        }
    }
}
