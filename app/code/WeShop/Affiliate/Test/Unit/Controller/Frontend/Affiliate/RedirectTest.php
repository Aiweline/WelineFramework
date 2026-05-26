<?php

declare(strict_types=1);

namespace WeShop\Affiliate\Test\Unit\Controller\Frontend\Affiliate;

use PHPUnit\Framework\TestCase;
use WeShop\Affiliate\Controller\Frontend\Affiliate\Redirect;
use WeShop\Affiliate\Service\AffiliateService;
use WeShop\Customer\Session\CustomerSession;
use Weline\Framework\Http\Request;

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

        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->expects($this->once())
            ->method('getUserId')
            ->willReturn(42);

        $request = $this->createMock(Request::class);
        $request->expects($this->once())
            ->method('getParam')
            ->with('code')
            ->willReturn(' AFF-CODE ');

        $controller = $this->getMockBuilder(Redirect::class)
            ->setConstructorArgs([$affiliateService, $customerSession])
            ->onlyMethods(['redirect'])
            ->getMock();

        $controller->expects($this->once())
            ->method('redirect')
            ->with('/product/frontend/product/view?id=652');

        $this->setProtectedProperty($controller, 'request', $request);

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
