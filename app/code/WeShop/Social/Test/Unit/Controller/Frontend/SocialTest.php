<?php

declare(strict_types=1);

namespace WeShop\Social\Test\Unit\Controller\Frontend;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Model\Customer;
use WeShop\Customer\Session\CustomerSession;
use WeShop\Social\Controller\Frontend\Social;
use WeShop\Social\Model\SocialShare;
use WeShop\Social\Service\SocialService;
use Weline\Framework\Http\Request;

final class SocialTest extends TestCase
{
    public function testIndexReturnsString(): void
    {
        $socialService = $this->createMock(SocialService::class);
        $socialService->expects($this->once())
            ->method('getProductShareUrls')
            ->with('https://example.com', 'Demo Product', ['facebook', 'x', 'linkedin', 'whatsapp', 'pinterest'])
            ->willReturn([
                ['platform' => 'facebook', 'label' => 'Facebook', 'url' => 'https://facebook.example'],
                ['platform' => 'x', 'label' => 'X', 'url' => 'https://x.example'],
            ]);

        $customerSession = $this->createMock(CustomerSession::class);
        $controller = $this->getMockBuilder(Social::class)
            ->setConstructorArgs([$socialService, $customerSession])
            ->onlyMethods(['assign', 'fetch'])
            ->getMock();

        $assignments = [];
        $controller->expects($this->exactly(5))
            ->method('assign')
            ->willReturnCallback(static function (string $key, mixed $value) use (&$assignments, $controller) {
                $assignments[$key] = $value;

                return $controller;
            });
        $controller->expects($this->once())
            ->method('fetch')
            ->with('WeShop_Social::templates/Frontend/Social/index.phtml')
            ->willReturn('social-page');

        $this->setControllerRequest($controller, $this->createRequestMock([
            'url' => 'https://example.com',
            'title' => 'Demo Product',
            'product_id' => 456,
        ]));

        self::assertSame('social-page', $controller->index());
        self::assertSame('Demo Product', $assignments['page_title'] ?? null);
        self::assertSame('https://example.com', $assignments['target_url'] ?? null);
        self::assertSame(456, $assignments['product_id'] ?? null);
        self::assertCount(2, $assignments['share_urls'] ?? []);
    }

    public function testIndexWithEmptyUrlReturnsEmptyShares(): void
    {
        $socialService = $this->createMock(SocialService::class);
        $socialService->expects($this->once())
            ->method('getProductShareUrls')
            ->with('', '', ['facebook', 'x', 'linkedin', 'whatsapp', 'pinterest'])
            ->willReturn([]);

        $customerSession = $this->createMock(CustomerSession::class);
        $controller = $this->getMockBuilder(Social::class)
            ->setConstructorArgs([$socialService, $customerSession])
            ->onlyMethods(['assign', 'fetch'])
            ->getMock();

        $assignments = [];
        $controller->expects($this->exactly(5))
            ->method('assign')
            ->willReturnCallback(static function (string $key, mixed $value) use (&$assignments, $controller) {
                $assignments[$key] = $value;

                return $controller;
            });
        $controller->expects($this->once())
            ->method('fetch')
            ->willReturn('social-empty-page');

        $this->setControllerRequest($controller, $this->createRequestMock());

        self::assertSame('social-empty-page', $controller->index());
        self::assertSame('Share This Page', $assignments['page_title'] ?? null);
        self::assertSame([], $assignments['share_urls'] ?? null);
    }

    public function testRecordShareReturnsSuccess(): void
    {
        $shareModel = $this->createMock(SocialShare::class);
        $shareModel->expects($this->once())
            ->method('getId')
            ->willReturn(1);

        $socialService = $this->createMock(SocialService::class);
        $socialService->expects($this->once())
            ->method('recordShare')
            ->with([
                'platform' => 'facebook',
                'customer_id' => 123,
                'product_id' => 456,
            ])
            ->willReturn($shareModel);

        $customer = $this->createMock(Customer::class);
        $customer->expects($this->exactly(2))
            ->method('getId')
            ->willReturn(123);

        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->expects($this->once())
            ->method('getCustomer')
            ->willReturn($customer);

        $controller = new Social($socialService, $customerSession);
        $this->setControllerRequest($controller, $this->createRequestMock([
            'platform' => 'facebook',
            'url' => 'https://example.com',
            'product_id' => 456,
        ]));

        $result = $controller->record();

        self::assertIsString($result);
        $data = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertSame(1, $data['data']['share_id']);
    }

    public function testRecordShareWithoutPlatformReturnsError(): void
    {
        $socialService = $this->createMock(SocialService::class);
        $socialService->expects($this->never())->method('recordShare');

        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->expects($this->never())->method('getCustomer');

        $controller = new Social($socialService, $customerSession);
        $this->setControllerRequest($controller, $this->createRequestMock([
            'platform' => '',
            'url' => 'https://example.com',
            'product_id' => 0,
        ]));

        $result = $controller->record();

        self::assertIsString($result);
        $data = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertStringContainsString('platform', (string) ($data['message'] ?? ''));
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
        $this->setControllerRequest($controller, $this->createRequestMock([
            'product_id' => 123,
        ]));

        $result = $controller->counts();

        self::assertIsString($result);
        $data = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertSame(42, $data['data']['count']);
    }

    public function testCountsWithInvalidProductIdReturnsError(): void
    {
        $socialService = $this->createMock(SocialService::class);
        $socialService->expects($this->never())->method('getShareCount');

        $customerSession = $this->createMock(CustomerSession::class);
        $controller = new Social($socialService, $customerSession);
        $this->setControllerRequest($controller, $this->createRequestMock([
            'product_id' => 0,
        ]));

        $result = $controller->counts();

        self::assertIsString($result);
        $data = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertStringContainsString('Invalid product ID', (string) ($data['message'] ?? ''));
    }

    private function createRequestMock(array $params = []): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getParam'])
            ->getMock();
        $request->method('getParam')
            ->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default);

        return $request;
    }

    private function setControllerRequest(object $controller, Request $request): void
    {
        $reflection = new \ReflectionObject($controller);
        while (!$reflection->hasProperty('request') && ($reflection = $reflection->getParentClass())) {
        }

        if (!$reflection instanceof \ReflectionClass) {
            self::fail('Unable to locate request property.');
        }

        $property = $reflection->getProperty('request');
        $property->setAccessible(true);
        $property->setValue($controller, $request);
    }
}
