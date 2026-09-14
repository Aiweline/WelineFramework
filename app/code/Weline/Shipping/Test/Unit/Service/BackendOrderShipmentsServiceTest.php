<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Service\BackendOrderShipmentsService;

final class BackendOrderShipmentsServiceTest extends TestCase
{
    public function testListForOrderIdReturnsEmptyForInvalidId(): void
    {
        $om = $this->createStub(ObjectManager::class);
        $om->method('getInstance')->willThrowException(new \RuntimeException('should_not_resolve'));
        $service = new BackendOrderShipmentsService($om);
        self::assertSame([], $service->listForOrderId(0));
        self::assertSame([], $service->listForOrderId(-1));
    }
}
