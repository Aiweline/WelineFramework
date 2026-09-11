<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\B2B\Service\B2BAdminService;
use Weline\B2B\Service\B2BConflictException;
use Weline\B2B\Service\B2BRolloutGate;
use Weline\B2B\Service\B2BService;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;

require_once dirname(__DIR__) . '/bootstrap.php';

final class B2BAdminRolloutMutableMessageTest extends TestCase
{
    public function testApproveRequiresRolloutAndSurfacesReadableConflict(): void
    {
        $gate = B2BRolloutGate::forTestingConfiguration(['mode' => CommerceRolloutGateInterface::MODE_OFF]);
        $service = B2BService::forTesting($gate);
        $admin = new B2BAdminService($service);

        try {
            $admin->approveMembershipApplication([
                'application_id' => 'app-missing',
                'group_id' => 'g-system-vip0',
                'website_id' => 0,
            ]);
            self::fail('expected rollout immutable conflict');
        } catch (B2BConflictException $exception) {
            self::assertSame('b2b_rollout_immutable', $exception->errorCode);
            self::assertStringContainsString('灰度', $exception->getMessage());
        }
    }
}
