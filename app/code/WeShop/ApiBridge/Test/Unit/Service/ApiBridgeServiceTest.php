<?php

declare(strict_types=1);

namespace WeShop\ApiBridge\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\ApiBridge\Service\ApiBridgeService;

class ApiBridgeServiceTest extends TestCase
{
    private ApiBridgeService $service;

    protected function setUp(): void
    {
        $this->service = new ApiBridgeService();
    }

    public function testGetApiEndpointsReturnsArray(): void
    {
        $endpoints = $this->service->getApiEndpoints();

        $this->assertIsArray($endpoints);
        $this->assertNotEmpty($endpoints);
    }

    public function testGetApiEndpointsContainsCartEndpoint(): void
    {
        $endpoints = $this->service->getApiEndpoints();

        $this->assertArrayHasKey('cart', $endpoints);
        $this->assertSame('Cart', $endpoints['cart']['name']);
        $this->assertSame('V1', $endpoints['cart']['version']);
    }

    public function testGetApiEndpointsContainsCheckoutEndpoint(): void
    {
        $endpoints = $this->service->getApiEndpoints();

        $this->assertArrayHasKey('checkout', $endpoints);
        $this->assertSame('Checkout', $endpoints['checkout']['name']);
        $this->assertSame('V1', $endpoints['checkout']['version']);
    }

    public function testGetApiEndpointsContainsAuthEndpoint(): void
    {
        $endpoints = $this->service->getApiEndpoints();

        $this->assertArrayHasKey('auth', $endpoints);
        $this->assertSame('Auth', $endpoints['auth']['name']);
        $this->assertSame('V1', $endpoints['auth']['version']);
    }

    public function testGetEndpointInfoReturnsCorrectInfo(): void
    {
        $info = $this->service->getEndpointInfo('cart');

        $this->assertIsArray($info);
        $this->assertSame('Cart', $info['name']);
        $this->assertSame('V1', $info['version']);
        $this->assertNotEmpty($info['description']);
    }

    public function testGetEndpointInfoReturnsNullForInvalidEndpoint(): void
    {
        $info = $this->service->getEndpointInfo('invalid_endpoint');

        $this->assertNull($info);
    }

    public function testEndpointExistsReturnsTrueForValidEndpoints(): void
    {
        $this->assertTrue($this->service->endpointExists('cart'));
        $this->assertTrue($this->service->endpointExists('checkout'));
        $this->assertTrue($this->service->endpointExists('auth'));
    }

    public function testEndpointExistsReturnsFalseForInvalidEndpoints(): void
    {
        $this->assertFalse($this->service->endpointExists('invalid'));
        $this->assertFalse($this->service->endpointExists(''));
        $this->assertFalse($this->service->endpointExists('CART'));
    }

    public function testBuildResponseWithSuccess(): void
    {
        $response = $this->service->buildResponse(
            true,
            ['key' => 'value'],
            'Success message',
            200,
            ['meta' => 'data']
        );

        $this->assertTrue($response['success']);
        $this->assertSame(200, $response['code']);
        $this->assertSame('Success message', $response['message']);
        $this->assertSame(['key' => 'value'], $response['data']);
        $this->assertSame(['meta' => 'data'], $response['meta']);
        $this->assertArrayHasKey('timestamp', $response);
    }

    public function testBuildResponseWithError(): void
    {
        $response = $this->service->buildResponse(
            false,
            null,
            'Error message',
            400
        );

        $this->assertFalse($response['success']);
        $this->assertSame(400, $response['code']);
        $this->assertSame('Error message', $response['message']);
        $this->assertArrayNotHasKey('data', $response);
    }

    public function testSuccessResponse(): void
    {
        $response = $this->service->successResponse(
            ['items' => [1, 2, 3]],
            'Operation successful'
        );

        $this->assertTrue($response['success']);
        $this->assertSame(200, $response['code']);
        $this->assertSame('Operation successful', $response['message']);
        $this->assertSame(['items' => [1, 2, 3]], $response['data']);
    }

    public function testErrorResponse(): void
    {
        $response = $this->service->errorResponse(
            'Something went wrong',
            500
        );

        $this->assertFalse($response['success']);
        $this->assertSame(500, $response['code']);
        $this->assertSame('Something went wrong', $response['message']);
    }

    public function testPaginatedResponse(): void
    {
        $items = [
            ['id' => 1, 'name' => 'Item 1'],
            ['id' => 2, 'name' => 'Item 2'],
        ];

        $response = $this->service->paginatedResponse($items, 1, 10, 25);

        $this->assertTrue($response['success']);
        $this->assertSame(200, $response['code']);
        $this->assertSame($items, $response['data']);
        $this->assertArrayHasKey('meta', $response);
        $this->assertArrayHasKey('pagination', $response['meta']);

        $pagination = $response['meta']['pagination'];
        $this->assertSame(1, $pagination['page']);
        $this->assertSame(10, $pagination['page_size']);
        $this->assertSame(25, $pagination['total']);
        $this->assertSame(3, $pagination['total_pages']);
        $this->assertTrue($pagination['has_next']);
        $this->assertFalse($pagination['has_prev']);
    }

    public function testPaginatedResponseSecondPage(): void
    {
        $items = [['id' => 11, 'name' => 'Item 11']];

        $response = $this->service->paginatedResponse($items, 2, 10, 25);

        $pagination = $response['meta']['pagination'];
        $this->assertTrue($pagination['has_next']);
        $this->assertTrue($pagination['has_prev']);
    }

    public function testPaginatedResponseLastPage(): void
    {
        $items = [['id' => 21, 'name' => 'Item 21']];

        $response = $this->service->paginatedResponse($items, 3, 10, 25);

        $pagination = $response['meta']['pagination'];
        $this->assertFalse($pagination['has_next']);
        $this->assertTrue($pagination['has_prev']);
    }

    public function testPaginatedResponseEmptyData(): void
    {
        $response = $this->service->paginatedResponse([], 1, 10, 0);

        $this->assertTrue($response['success']);
        $pagination = $response['meta']['pagination'];
        $this->assertSame(0, $pagination['total']);
        $this->assertSame(0, $pagination['total_pages']);
        $this->assertFalse($pagination['has_next']);
        $this->assertFalse($pagination['has_prev']);
    }

    public function testGetApiDocumentation(): void
    {
        $doc = $this->service->getApiDocumentation();

        $this->assertIsArray($doc);
        $this->assertSame('WeShop API Bridge', $doc['name']);
        $this->assertSame('1.0.0', $doc['version']);
        $this->assertArrayHasKey('endpoints', $doc);
        $this->assertCount(3, $doc['endpoints']);
    }

    public function testGetCartBridge(): void
    {
        $bridge = $this->service->getCartBridge();

        $this->assertInstanceOf(\WeShop\ApiBridge\Api\Rest\V1\Weshop\Cart::class, $bridge);
    }

    public function testGetCheckoutBridge(): void
    {
        $bridge = $this->service->getCheckoutBridge();

        $this->assertInstanceOf(\WeShop\ApiBridge\Api\Rest\V1\Weshop\Checkout::class, $bridge);
    }

    public function testGetAuthBridge(): void
    {
        $bridge = $this->service->getAuthBridge();

        $this->assertInstanceOf(\WeShop\ApiBridge\Api\Rest\V1\Weshop\Auth::class, $bridge);
    }

    public function testBuildResponseWithoutData(): void
    {
        $response = $this->service->buildResponse(true, null, 'Success', 201);

        $this->assertTrue($response['success']);
        $this->assertSame(201, $response['code']);
        $this->assertArrayNotHasKey('data', $response);
    }

    public function testBuildResponseWithEmptyMeta(): void
    {
        $response = $this->service->buildResponse(true, ['data' => 'value'], 'OK', 200, []);

        $this->assertArrayNotHasKey('meta', $response);
    }

    public function testErrorResponseWithData(): void
    {
        $response = $this->service->errorResponse(
            'Validation failed',
            422,
            ['field' => 'email', 'error' => 'Invalid format']
        );

        $this->assertFalse($response['success']);
        $this->assertSame(422, $response['code']);
        $this->assertSame(['field' => 'email', 'error' => 'Invalid format'], $response['data']);
    }
}
