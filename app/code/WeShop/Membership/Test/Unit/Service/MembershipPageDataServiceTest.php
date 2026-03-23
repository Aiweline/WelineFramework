<?php

declare(strict_types=1);

namespace WeShop\Membership\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Membership\Model\Membership;
use WeShop\Membership\Service\MembershipPageDataService;
use WeShop\Membership\Service\MembershipService;
use Weline\Framework\Http\Url;

class MembershipPageDataServiceTest extends TestCase
{
    public function testBuildMapsMembershipSummaryForExistingTier(): void
    {
        $membership = $this->createMock(Membership::class);
        $membership->method('getData')->willReturnCallback(
            static fn(string $field): mixed => match ($field) {
                Membership::schema_fields_LEVEL => 'silver',
                Membership::schema_fields_POINTS => 350,
                default => null,
            }
        );

        $membershipService = $this->createMock(MembershipService::class);
        $membershipService->expects($this->once())
            ->method('getCustomerMembership')
            ->with(6)
            ->willReturn($membership);

        $url = $this->createMock(Url::class);
        $url->expects($this->once())
            ->method('getUrl')
            ->with('membership')
            ->willReturn('/membership');

        $service = new MembershipPageDataService($membershipService, $url);
        $result = $service->build(6);

        $this->assertSame('/membership', $result['membership_url']);
        $this->assertSame('silver', $result['membership']['level_code']);
        $this->assertSame(350, $result['membership']['points']);
        $this->assertSame('gold', $result['membership']['next_level_code']);
        $this->assertSame(250, $result['membership']['points_to_next']);
        $this->assertGreaterThan(0, (int) $result['membership']['progress_percent']);
        $this->assertNotEmpty($result['benefits']);
        $this->assertCount(4, $result['tiers']);
    }

    public function testBuildFallsBackToBronzeWhenNoMembershipExists(): void
    {
        $membershipService = $this->createMock(MembershipService::class);
        $membershipService->expects($this->once())
            ->method('getCustomerMembership')
            ->with(10)
            ->willReturn(null);

        $url = $this->createMock(Url::class);
        $url->method('getUrl')->willReturn('/membership');

        $service = new MembershipPageDataService($membershipService, $url);
        $result = $service->build(10);

        $this->assertSame('bronze', $result['membership']['level_code']);
        $this->assertSame(0, $result['membership']['points']);
        $this->assertSame('silver', $result['membership']['next_level_code']);
        $this->assertSame(200, $result['membership']['points_to_next']);
        $this->assertFalse($result['membership']['is_top_tier']);
    }
}

