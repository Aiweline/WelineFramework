<?php

declare(strict_types=1);

namespace WeShop\Cart\Test\Unit\Api\Rest\V1;

use PHPUnit\Framework\TestCase;
use WeShop\Cart\Api\Rest\V1\Cart;
use WeShop\Cart\Service\CartApiPayloadService;
use WeShop\Customer\Api\CustomerContextInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Response;

class CartTest extends TestCase
{
    public function testPostAddReadsRequestPayloadAndDelegatesToPayloadService(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(27);

        $payloadService = $this->createMock(CartApiPayloadService::class);
        $payloadService->expects($this->once())
            ->method('buildAddResponse')
            ->with(27, [
                'product_id' => 9,
                'qty' => 2,
                'selected_options' => '[1,2]',
            ])
            ->willReturn([
                'code' => 200,
                'msg' => 'Added to cart successfully.',
                'data' => ['success' => true],
            ]);

        $request = new Request();
        $request->setPost('product_id', 9);
        $request->setPost('qty', 2);
        $request->setPost('selected_options', '[1,2]');

        $controller = $this->getMockBuilder(Cart::class)
            ->setConstructorArgs([$customerContext, $payloadService])
            ->onlyMethods(['fetchJson'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static function (array $payload): bool {
                return (int) ($payload['code'] ?? 0) === 200
                    && (string) ($payload['msg'] ?? '') === 'Added to cart successfully.'
                    && (bool) ($payload['data']['success'] ?? false);
            }))
            ->willReturn('add-json');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('add-json', $controller->postAdd());
    }

    public function testGetMiniItemsInjectsRenderedHtmlIntoPayloadData(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(12);

        $payloadService = $this->createMock(CartApiPayloadService::class);
        $payloadService->expects($this->once())
            ->method('buildMiniItemsResponse')
            ->with(12)
            ->willReturn([
                'code' => 200,
                'msg' => 'Mini cart loaded successfully.',
                'data' => [
                    'success' => true,
                    'items' => [['cart_id' => 8]],
                    'totals' => ['count' => 1],
                    'html' => '',
                ],
            ]);

        $controller = $this->getMockBuilder(Cart::class)
            ->setConstructorArgs([$customerContext, $payloadService])
            ->onlyMethods(['renderMiniItemsHtml', 'fetchJson'])
            ->getMock();
        $controller->expects($this->once())
            ->method('renderMiniItemsHtml')
            ->with($this->callback(static function (array $data): bool {
                return (int) ($data['totals']['count'] ?? 0) === 1
                    && (int) ($data['items'][0]['cart_id'] ?? 0) === 8;
            }))
            ->willReturn('<div>mini-cart</div>');
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static function (array $payload): bool {
                return (string) ($payload['data']['html'] ?? '') === '<div>mini-cart</div>';
            }))
            ->willReturn('mini-json');

        $this->assertSame('mini-json', $controller->getMiniItems());
    }

    public function testGetMiniItemsBuildsHtmlForItemsWithoutPageControllerTemplateHelpers(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(12);

        $payloadService = $this->createMock(CartApiPayloadService::class);
        $payloadService->expects($this->once())
            ->method('buildMiniItemsResponse')
            ->with(12)
            ->willReturn([
                'code' => 200,
                'msg' => 'Mini cart loaded successfully.',
                'data' => [
                    'success' => true,
                    'items' => [[
                        'cart_id' => 8,
                        'product_id' => 18,
                        'name' => 'Road Helmet',
                        'url' => '/product/road-helmet',
                        'price_formatted' => '$128.80',
                        'quantity' => 2,
                    ]],
                    'totals' => ['count' => 2],
                    'html' => '',
                ],
            ]);

        $controller = new Cart($customerContext, $payloadService);

        $response = $this->createMock(Response::class);
        $response->expects($this->once())
            ->method('setHttpResponseCode')
            ->with(200)
            ->willReturnSelf();
        $response->expects($this->once())
            ->method('setHeader')
            ->with('Content-Type', 'application/json; charset=utf-8')
            ->willReturnSelf();

        $request = $this->createMock(Request::class);
        $request->method('getResponse')->willReturn($response);

        $this->setProtectedProperty($controller, 'request', $request);

        $payload = json_decode($controller->getMiniItems(), true, 512, JSON_THROW_ON_ERROR);
        $html = (string) ($payload['data']['html'] ?? '');

        $this->assertSame(200, $payload['code'] ?? null);
        $this->assertStringContainsString('mini-cart-item', $html);
        $this->assertStringContainsString('Road Helmet', $html);
        $this->assertStringContainsString('data-item-id="8"', $html);
        $this->assertStringContainsString('data-action="increase-qty"', $html);
    }

    public function testPostAddKeepsTransportStatus200AndNumericPayloadCodes(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(null);

        $payloadService = $this->createMock(CartApiPayloadService::class);
        $payloadService->expects($this->once())
            ->method('buildAddResponse')
            ->willReturn([
                'code' => 401,
                'msg' => 'Please log in first',
                'data' => [
                    'success' => false,
                    'requires_login' => true,
                ],
            ]);

        $controller = new Cart($customerContext, $payloadService);

        $response = $this->createMock(Response::class);
        $response->expects($this->once())
            ->method('setHttpResponseCode')
            ->with(200)
            ->willReturnSelf();
        $response->expects($this->once())
            ->method('setHeader')
            ->with('Content-Type', 'application/json; charset=utf-8')
            ->willReturnSelf();

        $request = $this->createMock(Request::class);
        $request->method('getResponse')->willReturn($response);
        $request->method('getBodyParam')->willReturn(null);
        $request->method('getPost')->willReturnMap([
            ['product_id', null, 0],
            ['qty', null, 1],
            ['selected_options', null, null],
        ]);
        $request->method('getParam')->willReturn(null);

        $this->setProtectedProperty($controller, 'request', $request);

        $payload = json_decode($controller->postAdd(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(401, $payload['code'] ?? null);
        $this->assertSame('Please log in first', $payload['msg'] ?? null);
        $this->assertTrue((bool) ($payload['data']['requires_login'] ?? false));
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
