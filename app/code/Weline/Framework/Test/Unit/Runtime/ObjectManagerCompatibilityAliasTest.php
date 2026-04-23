<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;

final class ObjectManagerCompatibilityAliasTest extends TestCase
{
    protected function tearDown(): void
    {
        ObjectManager::clearInstances();
    }

    public function testGetWithoutClassReturnsObjectManagerInstance(): void
    {
        self::assertInstanceOf(ObjectManager::class, ObjectManager::get());
    }

    public function testGetForClassDelegatesToGetInstance(): void
    {
        $instance = ObjectManager::get(ObjectManagerGetAliasFixture::class, [], false);

        self::assertInstanceOf(ObjectManagerGetAliasFixture::class, $instance);
    }
}

final class ObjectManagerGetAliasFixture
{
    public function __construct()
    {
    }
}
