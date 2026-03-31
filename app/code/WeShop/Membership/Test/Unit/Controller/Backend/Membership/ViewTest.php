<?php

declare(strict_types=1);

namespace WeShop\Membership\Test\Unit\Controller\Backend\Membership;

use PHPUnit\Framework\TestCase;
use WeShop\Membership\Controller\Backend\Membership\View;
use WeShop\Membership\Service\MembershipAdminPageDataService;
use WeShop\Membership\Service\MembershipService;

class ViewTest extends TestCase
{
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(View::class));
    }

    public function testControllerHasIndexMethod(): void
    {
        $reflection = new \ReflectionClass(View::class);
        $this->assertTrue($reflection->hasMethod('index'));
    }

    public function testControllerHasRequiredDependencies(): void
    {
        $reflection = new \ReflectionClass(View::class);
        $constructor = $reflection->getConstructor();
        $parameters = $constructor->getParameters();

        $this->assertCount(1, $parameters);
        $this->assertSame('membershipAdminPageDataService', $parameters[0]->getName());
        $this->assertSame(MembershipAdminPageDataService::class, $parameters[0]->getType()->getName());
    }

    public function testControllerHasHelperMethods(): void
    {
        $reflection = new \ReflectionClass(View::class);
        $this->assertTrue($reflection->hasMethod('getMembershipRecord'));
        $this->assertTrue($reflection->hasMethod('getLevelOptions'));
        $this->assertTrue($reflection->hasMethod('getLevelBenefits'));
    }

    public function testIndexMethodIsPublic(): void
    {
        $reflection = new \ReflectionClass(View::class);
        $indexMethod = $reflection->getMethod('index');
        $this->assertTrue($indexMethod->isPublic());
    }

    public function testHelperMethodsArePublic(): void
    {
        $membershipService = $this->createMock(MembershipService::class);
        $membershipService->method('getLevelOptions')->willReturn([
            'bronze' => 'Bronze',
            'silver' => 'Silver',
            'gold' => 'Gold',
            'platinum' => 'Platinum',
        ]);
        $membershipService->method('getMembershipRecord')->willReturn(null);

        $adminPageDataService = new MembershipAdminPageDataService($membershipService);
        $controller = new View($adminPageDataService);

        $this->assertIsArray($controller->getLevelOptions());
        $this->assertIsArray($controller->getLevelBenefits());
        $this->assertNull($controller->getMembershipRecord(0));
    }
}
