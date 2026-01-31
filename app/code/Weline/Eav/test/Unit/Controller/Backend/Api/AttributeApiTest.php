<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\Controller\Backend\Api;

use PHPUnit\Framework\TestCase;
use Weline\Eav\Controller\Backend\Api\Attribute;

/**
 * @covers \Weline\Eav\Controller\Backend\Api\Attribute
 */
class AttributeApiTest extends TestCase
{
    /**
     * Test Attribute controller can be instantiated
     */
    public function testControllerCanBeInstantiated(): void
    {
        $this->assertTrue(class_exists(Attribute::class));
    }

    /**
     * Test Attribute controller has CRUD methods
     */
    public function testControllerHasCrudMethods(): void
    {
        $methods = [
            'getIndex',
            'getDetail',
            'postSave',
            'postDelete',
            'getTypes',
            'getSearch',
        ];

        foreach ($methods as $method) {
            $this->assertTrue(
                method_exists(Attribute::class, $method),
                "Attribute controller should have {$method} method"
            );
        }
    }
}
