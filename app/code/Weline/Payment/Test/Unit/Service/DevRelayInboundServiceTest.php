<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Service\DevRelayInboundService;
use Weline\Payment\Service\DevRelayCommandService;

final class DevRelayInboundServiceTest extends TestCase
{
    public function testInboundAndCommandServicesExist(): void
    {
        self::assertTrue(class_exists(DevRelayInboundService::class));
        self::assertTrue(class_exists(DevRelayCommandService::class));
    }
}
