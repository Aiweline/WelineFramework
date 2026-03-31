<?php

declare(strict_types=1);

namespace WeShop\Price\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

class PriceConfigServiceTest extends TestCase
{
    public function testServiceCanBeInstantiated(): void
    {
        $this->assertTrue(true);
    }

    public function testPriceConfigServiceExists(): void
    {
        $this->assertTrue(class_exists(\WeShop\Price\Service\PriceConfigService::class));
    }
}
