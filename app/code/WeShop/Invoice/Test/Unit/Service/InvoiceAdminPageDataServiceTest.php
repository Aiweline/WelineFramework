<?php

declare(strict_types=1);

namespace WeShop\Invoice\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Invoice\Service\InvoiceAdminPageDataService;

class InvoiceAdminPageDataServiceTest extends TestCase
{
    public function testServiceClassExists(): void
    {
        $this->assertTrue(class_exists(InvoiceAdminPageDataService::class));
    }

    public function testServiceExposesListAndDetailMethods(): void
    {
        $reflection = new \ReflectionClass(InvoiceAdminPageDataService::class);
        $this->assertTrue($reflection->hasMethod('getListData'));
        $this->assertTrue($reflection->hasMethod('getDetailData'));
    }
}
