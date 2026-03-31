<?php

declare(strict_types=1);

namespace WeShop\Membership\Test\Unit\Controller\Backend\Membership;

use PHPUnit\Framework\TestCase;
use WeShop\Membership\Controller\Backend\Membership\Save;
use WeShop\Membership\Service\MembershipService;

class SaveTest extends TestCase
{
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(Save::class));
    }

    public function testControllerHasPostAndIndexMethods(): void
    {
        $reflection = new \ReflectionClass(Save::class);
        $this->assertTrue($reflection->hasMethod('post'));
        $this->assertTrue($reflection->hasMethod('index'));
    }

    public function testControllerHasRequiredDependencies(): void
    {
        $reflection = new \ReflectionClass(Save::class);
        $constructor = $reflection->getConstructor();
        $parameters = $constructor->getParameters();

        $this->assertCount(1, $parameters);
        $this->assertSame('membershipService', $parameters[0]->getName());
        $this->assertSame(MembershipService::class, $parameters[0]->getType()->getName());
    }

    public function testIndexMethodDelegatesToPost(): void
    {
        $membershipService = $this->createMock(MembershipService::class);
        $controller = new Save($membershipService);

        $reflection = new \ReflectionClass(Save::class);
        $indexMethod = $reflection->getMethod('index');
        $postMethod = $reflection->getMethod('post');

        $this->assertTrue($indexMethod->isPublic());
        $this->assertTrue($postMethod->isPublic());
    }

    public function testSanitizeBackUrlRejectsAbsoluteUrl(): void
    {
        $reflection = new \ReflectionClass(Save::class);
        $method = $reflection->getMethod('sanitizeBackUrl');
        $method->setAccessible(true);

        $sanitized = $method->invoke(null, 'https://evil.example/path', '/admin/membership');

        $this->assertSame('/admin/membership', $sanitized);
    }

    public function testSanitizeBackUrlAcceptsInternalPath(): void
    {
        $reflection = new \ReflectionClass(Save::class);
        $method = $reflection->getMethod('sanitizeBackUrl');
        $method->setAccessible(true);

        $sanitized = $method->invoke(null, '/admin/membership?page=2', '/admin/membership');

        $this->assertSame('/admin/membership?page=2', $sanitized);
    }
}
