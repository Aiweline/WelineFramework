<?php

declare(strict_types=1);

namespace Weline\Saas\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;
use Weline\Saas\Controller\Backend\Provisioning;
use Weline\Websites\Controller\Backend\Provisioning as WebsitesProvisioning;

class ProvisioningTest extends TestCase
{
    public function testLegacyProvisioningAliasExtendsWebsitesProvisioning(): void
    {
        $reflection = new \ReflectionClass(Provisioning::class);

        self::assertTrue(class_exists(Provisioning::class));
        self::assertTrue($reflection->hasMethod('index'));
        self::assertTrue($reflection->isSubclassOf(WebsitesProvisioning::class));
    }
}
