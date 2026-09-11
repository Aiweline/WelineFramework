<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\B2B\Controller\Backend\ControlCenter;
use Weline\Framework\Acl\Acl;

require_once dirname(__DIR__) . '/bootstrap.php';

final class ControlCenterDeleteAclContractTest extends TestCase
{
    public function testDeleteMembershipApplicationHasDedicatedAclSource(): void
    {
        $method = (new ReflectionClass(ControlCenter::class))
            ->getMethod('removeMembershipApplication');
        $attrs = $method->getAttributes(Acl::class);
        self::assertNotEmpty($attrs);
        $acl = $attrs[0]->newInstance();
        self::assertSame(
            'Weline_B2B::commerce:partner:applications:delete',
            $acl->getData('source_id'),
        );
    }
}
