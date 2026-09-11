<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\B2B\Service\B2BAdminService;
use Weline\B2B\Service\B2BConflictException;
use Weline\B2B\Service\B2BRolloutGate;
use Weline\B2B\Service\B2BService;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;

require_once dirname(__DIR__) . '/bootstrap.php';

final class B2BAdminRevokeMultiWebsiteTest extends TestCase
{
    public function testNormalizeWebsiteIdsAcceptsCsvArrayAndDedupes(): void
    {
        $admin = new B2BAdminService(
            B2BService::forTesting(
                B2BRolloutGate::forTestingConfiguration(['mode' => CommerceRolloutGateInterface::MODE_ON]),
            ),
        );
        $method = new ReflectionMethod(B2BAdminService::class, 'normalizeWebsiteIds');
        $method->setAccessible(true);

        self::assertSame([0, 1], $method->invoke($admin, ['website_ids' => '0,1']));
        self::assertSame([0], $method->invoke($admin, ['website_id' => '0']));
        self::assertSame([2, 3], $method->invoke($admin, ['website_ids' => ['2', '3', '2']]));
        self::assertSame([4], $method->invoke($admin, ['website_ids' => '4,x,4']));
        self::assertSame([], $method->invoke($admin, ['website_ids' => '']));
    }

    public function testRevokeMembershipRejectsEmptyWebsiteIds(): void
    {
        $admin = new B2BAdminService(
            B2BService::forTesting(
                B2BRolloutGate::forTestingConfiguration(['mode' => CommerceRolloutGateInterface::MODE_ON]),
            ),
        );

        $this->expectException(B2BConflictException::class);
        $this->expectExceptionMessage('撤销资格参数非法');
        $admin->revokeMembership([
            'customer_id' => '47',
            'website_ids' => '',
        ]);
    }
}
