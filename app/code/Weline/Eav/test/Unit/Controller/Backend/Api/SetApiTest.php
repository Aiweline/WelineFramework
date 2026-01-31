<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\Controller\Backend\Api;

use PHPUnit\Framework\TestCase;
use Weline\Eav\Controller\Backend\Api\Set;

/**
 * @covers \Weline\Eav\Controller\Backend\Api\Set
 */
class SetApiTest extends TestCase
{
    /**
     * Test Set controller can be instantiated
     */
    public function testControllerCanBeInstantiated(): void
    {
        $this->assertTrue(class_exists(Set::class));
    }

    /**
     * Test Set controller has CRUD methods
     */
    public function testControllerHasCrudMethods(): void
    {
        $methods = [
            'getIndex',
            'getDetail',
            'postSave',
            'postDelete',
            'getSearch',
        ];

        foreach ($methods as $method) {
            $this->assertTrue(
                method_exists(Set::class, $method),
                "Set controller should have {$method} method"
            );
        }
    }
}
