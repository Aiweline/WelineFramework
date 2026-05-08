<?php

declare(strict_types=1);

namespace WeShop\Invoice\Test\Unit\Api\Rest\V1;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Invoice\Api\Rest\V1\Invoice;
use WeShop\Invoice\Service\InvoicePageDataService;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Response;

class InvoiceTest extends TestCase
{
    public function testGetListReturnsUnauthorizedPayloadForGuests(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(null);

        $api = $this->getMockBuilder(Invoice::class)
            ->setConstructorArgs([
                $customerContext,
                $this->createMock(InvoicePageDataService::class),
            ])
            ->onlyMethods(['fetchJson'])
            ->getMock();

        $api->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static function (array $payload): bool {
                return $payload['code'] === 401
                    && ($payload['data']['invoices'] ?? null) === [];
            }))
            ->willReturn('guest');

        $this->assertSame('guest', $api->getList());
    }

    public function testGetListReturnsInvoicePayloadForLoggedInCustomer(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(12);

        $pageDataService = $this->createMock(InvoicePageDataService::class);
        $pageDataService->expects($this->once())
            ->method('build')
            ->with(12, 2, 15)
            ->willReturn([
                'invoices' => [['invoice_id' => 301]],
                'invoice_count' => 1,
                'invoice_pending_count' => 0,
                'invoice_issued_count' => 1,
            ]);

        $api = $this->getMockBuilder(Invoice::class)
            ->setConstructorArgs([
                $customerContext,
                $pageDataService,
            ])
            ->onlyMethods(['fetchJson'])
            ->getMock();

        $request = $this->createMock(Request::class);
        $request->method('getParam')->willReturnCallback($this->requestParams([
            'page' => 2,
            'page_size' => 15,
        ]));
        $this->setProtectedProperty($api, 'request', $request);

        $api->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static function (array $payload): bool {
                return $payload['code'] === 200
                    && ($payload['data']['invoice_count'] ?? null) === 1
                    && ($payload['data']['invoices'][0]['invoice_id'] ?? null) === 301;
            }))
            ->willReturn('ok');

        $this->assertSame('ok', $api->getList());
    }

    public function testGetListRendersRealJsonPayloadForLoggedInCustomer(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(12);

        $pageDataService = $this->createMock(InvoicePageDataService::class);
        $pageDataService->expects($this->once())
            ->method('build')
            ->with(12, 1, 20)
            ->willReturn([
                'invoices' => [['invoice_id' => 301]],
                'invoice_count' => 1,
                'invoice_pending_count' => 0,
                'invoice_issued_count' => 1,
            ]);

        $api = new Invoice(
            $customerContext,
            $pageDataService,
        );

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
        $request->method('getParam')
            ->willReturnCallback(static function (string $key, mixed $default = null): mixed {
                return match ($key) {
                    'page', 'page_size' => null,
                    default => $default,
                };
            });
        $request->method('getResponse')->willReturn($response);
        $this->setProtectedProperty($api, 'request', $request);

        $payload = json_decode($api->getList(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(200, $payload['code'] ?? null);
        $this->assertSame(301, $payload['data']['invoices'][0]['invoice_id'] ?? null);
        $this->assertSame(1, $payload['data']['invoice_count'] ?? null);
    }

    public function testGetListClampsPageSizeToMaximumLimit(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(12);

        $pageDataService = $this->createMock(InvoicePageDataService::class);
        $pageDataService->expects($this->once())
            ->method('build')
            ->with(12, 1, 50)
            ->willReturn([
                'invoices' => [],
                'invoice_count' => 0,
                'invoice_pending_count' => 0,
                'invoice_issued_count' => 0,
            ]);

        $api = $this->getMockBuilder(Invoice::class)
            ->setConstructorArgs([
                $customerContext,
                $pageDataService,
            ])
            ->onlyMethods(['fetchJson'])
            ->getMock();

        $request = $this->createMock(Request::class);
        $request->method('getParam')->willReturnCallback($this->requestParams([
            'page' => 1,
            'page_size' => 999,
        ]));
        $this->setProtectedProperty($api, 'request', $request);

        $api->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static fn(array $payload): bool => ($payload['code'] ?? null) === 200))
            ->willReturn('ok');

        $this->assertSame('ok', $api->getList());
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

    /**
     * @param array<string,mixed> $params
     */
    private function requestParams(array $params): \Closure
    {
        return static fn(string $key, mixed $default = null): mixed => \array_key_exists($key, $params)
            ? $params[$key]
            : $default;
    }
}
