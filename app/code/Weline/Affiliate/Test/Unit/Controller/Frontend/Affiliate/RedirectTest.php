<?php

declare(strict_types=1);

namespace Weline\Affiliate\Test\Unit\Controller\Frontend\Affiliate;

use PHPUnit\Framework\TestCase;
use Weline\Affiliate\Controller\Frontend\Affiliate\Redirect;
use Weline\Affiliate\Service\AffiliateService;
use Weline\Framework\Http\Request;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;

class RedirectTest extends TestCase
{
    public function testIndexRedirectsToRecordedShareTarget(): void
    {
        $affiliateService = $this->createMock(AffiliateService::class);
        $affiliateService->expects($this->once())
            ->method('recordShareClick')
            ->with('AFF-CODE', 42)
            ->willReturn([
                'target_url' => '/product/frontend/product/view?id=652',
            ]);

        $session = $this->createMock(AuthenticatedSessionInterface::class);
        $session->expects($this->once())
            ->method('getUserId')
            ->willReturn(42);

        $request = $this->createMock(Request::class);
        $request->expects($this->once())
            ->method('getParam')
            ->with('code')
            ->willReturn(' AFF-CODE ');

        $controller = $this->getMockBuilder(Redirect::class)
            ->setConstructorArgs([$affiliateService])
            ->onlyMethods(['redirect'])
            ->getMock();

        $controller->expects($this->once())
            ->method('redirect')
            ->with('/product/frontend/product/view?id=652');

        $this->setProtectedProperty($controller, 'request', $request);
        $this->setProtectedProperty($controller, 'session', $session);

        $this->assertSame('', $controller->index());
    }

    private function setProtectedProperty(object $target, string $property, mixed $value): void
    {
        $reflection = new \ReflectionObject($target);
        while (!$reflection->hasProperty($property) && ($reflection = $reflection->getParentClass())) {
        }

        $reflectionProperty = $reflection->getProperty($property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($target, $value);
    }
}
