<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\Controller\Backend\Api;

use PHPUnit\Framework\TestCase;
use Weline\Eav\Controller\Backend\Api\Entity;

/**
 * @covers \Weline\Eav\Controller\Backend\Api\Entity
 */
class EntityApiTest extends TestCase
{
    /**
     * Test Entity controller can be instantiated
     */
    public function testControllerCanBeInstantiated(): void
    {
        $this->assertTrue(class_exists(Entity::class));
    }

    /**
     * Test Entity controller has CRUD methods
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
                method_exists(Entity::class, $method),
                "Entity controller should have {$method} method"
            );
        }
    }
}
