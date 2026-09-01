<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Service\DevRelayCommandService;

final class DevRelayCommandServiceTest extends TestCase
{
    public function testCommandServiceIsFinal(): void
    {
        $reflection = new \ReflectionClass(DevRelayCommandService::class);
        self::assertTrue($reflection->isFinal());
    }
}
