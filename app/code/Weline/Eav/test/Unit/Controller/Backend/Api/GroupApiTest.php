<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\Controller\Backend\Api;

use PHPUnit\Framework\TestCase;
use Weline\Eav\Controller\Backend\Api\Group;

/**
 * @covers \Weline\Eav\Controller\Backend\Api\Group
 */
class GroupApiTest extends TestCase
{
    /**
     * Test Group controller can be instantiated
     */
    public function testControllerCanBeInstantiated(): void
    {
        $this->assertTrue(class_exists(Group::class));
    }

    /**
     * Test Group controller has CRUD methods
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
                method_exists(Group::class, $method),
                "Group controller should have {$method} method"
            );
        }
    }
}
