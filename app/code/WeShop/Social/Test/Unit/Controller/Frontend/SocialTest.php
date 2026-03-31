<?php

declare(strict_types=1);

namespace WeShop\Social\Test\Unit\Controller\Frontend;

use PHPUnit\Framework\TestCase;
use WeShop\Social\Controller\Frontend\Social;
use WeShop\Social\Service\SocialService;
use WeShop\Customer\Session\CustomerSession;
use WeShop\Customer\Model\Customer;

class SocialTest extends TestCase
{
    public function testIndexReturnsString(): void
    {
        $socialService = $this->createMock(SocialService::class);
        $socialService->expects($this->once())
            ->method('getProductShareUrls')
            ->willReturn([
                [
                    'platform' => 'facebook',
                    'label' => 'Facebook',
                    'url' => 'https://www.facebook.com/sharer/sharer.php?u=https%3A%2F%2Fexample.com',
                ],
                [
                    'platform' => 'x',
                    'label' => 'X',
                    'url' => 'https://twitter.com/intent/tweet?url=https%3A%2F%2Fexample.com',
                ],
            ]);

        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->expects($this->once())
            ->method('getCustomer')
            ->willReturn(null);

        $controller = new Social($socialService, $customerSession);

        $result = $controller->index();

        $this->assertIsString($result);
        $this->assertStringContainsString('social-share-page', $result);
    }

    public function testIndexWithEmptyUrlReturnsEmptyShares(): void
    {
        $socialService = $this->createMock(SocialService::class);
        $socialService->expects($this->once())
            ->method('getProductShareUrls')
            ->willReturn([]);

        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->expects($this->once())
            ->method('getCustomer')
            ->willReturn(null);

        $controller = new Social($socialService, $customerSession);

        $result = $controller->index();

        $this->assertIsString($result);
        $this->assertStringContainsString('social-share__empty', $result);
    }

    public function testRecordShareReturnsSuccess(): void
    {
        $shareModel = $this->createMock(\WeShop\Social\Model\SocialShare::class);
        $shareModel->expects($this->once())
            ->method('getId')
            ->willReturn(1);

        $socialService = $this->createMock(SocialService::class);
        $socialService->expects($this->once())
            ->method('recordShare')
            ->willReturn($shareModel);

        $customer = $this->createMock(Customer::class);
        $customer->expects($this->once())
            ->method('getId')
            ->willReturn(123);

        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->expects($this->once())
            ->method('getCustomer')
            ->willReturn($customer);

        $controller = new Social($socialService, $customerSession);

        $reflection = new \ReflectionClass($controller);
        $requestProperty = $reflection->getProperty('request');
        $requestProperty->setAccessible(true);

        $request = $this->createMock(\Weline\Framework\Http\Request\Request::class);
        $request->expects($this->any())
            ->method('getParam')
            ->willReturnCallback(function ($key, $default = null) {
                return match ($key) {
                    'platform' => 'facebook',
                    'url' => 'https://example.com',
                    'product_id' => 456,
                    default => $default,
                };
            });

        $requestProperty->setValue($controller, $request);

        $result = $controller->record();

        $this->assertIsString($result);
        $data = json_decode($result, true);
        $this->assertTrue($data['success']);
        $this->assertEquals(1, $data['data']['share_id']);
    }

    public function testRecordShareWithoutPlatformReturnsError(): void
    {
        $socialService = $this->createMock(SocialService::class);
        $socialService->expects($this->never())
            ->method('recordShare');

        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->expects($this->once())
            ->method('getCustomer')
            ->willReturn(null);

        $controller = new Social($socialService, $customerSession);

        $reflection = new \ReflectionClass($controller);
        $requestProperty = $reflection->getProperty('request');
        $requestProperty->setAccessible(true);

        $request = $this->createMock(\Weline\Framework\Http\Request\Request::class);
        $request->expects($this->any())
            ->method('getParam')
            ->willReturnCallback(function ($key, $default = null) {
                return match ($key) {
                    'platform' => '',
                    'url' => 'https://example.com',
                    'product_id' => 0,
                    default => $default,
                };
            });

        $requestProperty->setValue($controller, $request);

        $result = $controller->record();

        $this->assertIsString($result);
        $data = json_decode($result, true);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('platform', $data['message']);
    }

    public function testCountsReturnsShareCount(): void
    {
        $socialService = $this->createMock(SocialService::class);
        $socialService->expects($this->once())
            ->method('getShareCount')
            ->with(123, null)
            ->willReturn(42);

        $customerSession = $this->createMock(CustomerSession::class);

        $controller = new Social($socialService, $customerSession);

        $reflection = new \ReflectionClass($controller);
        $requestProperty = $reflection->getProperty('request');
        $requestProperty->setAccessible(true);

        $request = $this->createMock(\Weline\Framework\Http\Request\Request::class);
        $request->expects($this->any())
            ->method('getParam')
            ->willReturnCallback(function ($key, $default = null) {
                return match ($key) {
                    'product_id' => 123,
                    default => $default,
                };
            });

        $requestProperty->setValue($controller, $request);

        $result = $controller->counts();

        $this->assertIsString($result);
        $data = json_decode($result, true);
        $this->assertTrue($data['success']);
        $this->assertEquals(42, $data['data']['count']);
    }

    public function testCountsWithInvalidProductIdReturnsError(): void
    {
        $socialService = $this->createMock(SocialService::class);
        $socialService->expects($this->never())
            ->method('getShareCount');

        $customerSession = $this->createMock(CustomerSession::class);

        $controller = new Social($socialService, $customerSession);

        $reflection = new \ReflectionClass($controller);
        $requestProperty = $reflection->getProperty('request');
        $requestProperty->setAccessible(true);

        $request = $this->createMock(\Weline\Framework\Http\Request\Request::class);
        $request->expects($this->any())
            ->method('getParam')
            ->willReturnCallback(function ($key, $default = null) {
                return match ($key) {
                    'product_id' => 0,
                    default => $default,
                };
            });

        $requestProperty->setValue($controller, $request);

        $result = $controller->counts();

        $this->assertIsString($result);
        $data = json_decode($result, true);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Invalid product ID', $data['message']);
    }
}
