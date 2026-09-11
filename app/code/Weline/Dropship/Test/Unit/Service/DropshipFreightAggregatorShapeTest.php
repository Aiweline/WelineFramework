<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Dropship\Service\DropshipFreightAggregator;
use Weline\Dropship\Service\DropshipPricingService;

final class DropshipFreightAggregatorShapeTest extends TestCase
{
    public function testAggregatorClassExists(): void
    {
        self::assertTrue(class_exists(DropshipFreightAggregator::class));
        self::assertTrue(class_exists(DropshipPricingService::class));
    }
}
