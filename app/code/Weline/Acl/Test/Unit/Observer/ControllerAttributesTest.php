<?php
declare(strict_types=1);

namespace Weline\Acl\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Acl\Model\Acl;
use Weline\Acl\Observer\ControllerAttributes;

final class ControllerAttributesTest extends TestCase
{
    public function testNormalizeAclDataForPersistenceCastsIntegerFlagsForPgsql(): void
    {
        $observer = new ControllerAttributes($this->createMock(Acl::class));
        $method = new ReflectionMethod($observer, 'normalizeAclDataForPersistence');
        $method->setAccessible(true);

        $normalized = $method->invoke($observer, [
            Acl::schema_fields_ACL_ID => '',
            Acl::schema_fields_ORDER => '',
            Acl::schema_fields_IS_ENABLE => true,
            Acl::schema_fields_IS_BACKEND => false,
            Acl::schema_fields_API_EXPOSABLE => 'false',
        ]);

        self::assertArrayNotHasKey(Acl::schema_fields_ACL_ID, $normalized);
        self::assertSame(0, $normalized[Acl::schema_fields_ORDER]);
        self::assertSame(1, $normalized[Acl::schema_fields_IS_ENABLE]);
        self::assertSame(0, $normalized[Acl::schema_fields_IS_BACKEND]);
        self::assertSame(0, $normalized[Acl::schema_fields_API_EXPOSABLE]);
    }
}
