<?php

declare(strict_types=1);

namespace WeShop\Compliance\Test\Unit\Controller\Frontend\Consent;

use PHPUnit\Framework\TestCase;
use WeShop\Compliance\Controller\Frontend\Consent\Save;
use WeShop\Compliance\Service\ConsentService;
use WeShop\Customer\Api\CustomerContextInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;

class SaveTest extends TestCase
{
    public function testIndexRejectsGuestForNonCookieConsent(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())->method('getUserId')->willReturn(null);

        $consentService = $this->createMock(ConsentService::class);
        $consentService->expects($this->never())->method('saveConsent');

        $url = $this->createMock(Url::class);
        $url->expects($this->once())
            ->method('getUrl')
            ->with('customer/account/login')
            ->willReturn('/customer/account/login');

        $request = $this->createMock(Request::class);
        $request->method('body')->willReturnMap([
            ['consent_type', null, 'privacy'],
            ['is_accepted', null, 1],
        ]);
        $request->method('getPost')->willReturnMap([
            ['consent_type', null, null],
            ['is_accepted', null, null],
        ]);
        $request->method('getParam')->willReturnMap([
            ['consent_type', null, null],
            ['is_accepted', null, null],
        ]);

        $controller = $this->getMockBuilder(Save::class)
            ->setConstructorArgs([$customerContext, $consentService, $url])
            ->onlyMethods(['fetchJson'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static function (array $payload): bool {
                return ($payload['success'] ?? true) === false
                    && ($payload['data']['redirect_url'] ?? null) === '/customer/account/login';
            }))
            ->willReturn('json');
        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('json', $controller->index());
    }

    public function testIndexSavesConsentAndReturnsStatuses(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())->method('getUserId')->willReturn(18);

        $consentService = $this->createMock(ConsentService::class);
        $consentService->expects($this->once())
            ->method('saveConsent')
            ->with($this->callback(static function (array $payload): bool {
                return (int) ($payload['customer_id'] ?? 0) === 18
                    && (string) ($payload['consent_type'] ?? '') === 'privacy'
                    && (int) ($payload['is_accepted'] ?? 0) === 1;
            }));
        $consentService->expects($this->once())
            ->method('getConsentStatuses')
            ->with(18)
            ->willReturn(['privacy' => true]);

        $url = $this->createMock(Url::class);
        $request = $this->createMock(Request::class);
        $request->method('body')->willReturnMap([
            ['consent_type', null, 'privacy'],
            ['is_accepted', null, 1],
        ]);
        $request->method('getPost')->willReturnMap([
            ['consent_type', null, null],
            ['is_accepted', null, null],
        ]);
        $request->method('getParam')->willReturnMap([
            ['consent_type', null, null],
            ['is_accepted', null, null],
        ]);

        $controller = $this->getMockBuilder(Save::class)
            ->setConstructorArgs([$customerContext, $consentService, $url])
            ->onlyMethods(['fetchJson'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static function (array $payload): bool {
                return (bool) ($payload['success'] ?? false)
                    && (bool) ($payload['data']['statuses']['privacy'] ?? false);
            }))
            ->willReturn('json');
        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('json', $controller->index());
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

