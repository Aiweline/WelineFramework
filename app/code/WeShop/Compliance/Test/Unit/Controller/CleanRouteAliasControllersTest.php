<?php

declare(strict_types=1);

namespace WeShop\Compliance\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use WeShop\Compliance\Controller\Consent;
use WeShop\Compliance\Controller\Consent\Save;
use WeShop\Compliance\Controller\Index;
use WeShop\Compliance\Controller\Privacy;

class CleanRouteAliasControllersTest extends TestCase
{
    public function testComplianceIndexAliasExists(): void
    {
        $reflection = new \ReflectionClass(Index::class);

        $this->assertTrue(class_exists(Index::class));
        $this->assertTrue($reflection->hasMethod('index'));
        $this->assertTrue($reflection->isSubclassOf(\WeShop\Compliance\Controller\Frontend\Compliance\Index::class));
    }

    public function testCompliancePrivacyAliasExists(): void
    {
        $reflection = new \ReflectionClass(Privacy::class);

        $this->assertTrue(class_exists(Privacy::class));
        $this->assertTrue($reflection->hasMethod('index'));
        $this->assertTrue($reflection->isSubclassOf(\WeShop\Compliance\Controller\Frontend\Compliance\Privacy::class));
    }

    public function testComplianceConsentAliasExists(): void
    {
        $reflection = new \ReflectionClass(Consent::class);

        $this->assertTrue(class_exists(Consent::class));
        $this->assertTrue($reflection->hasMethod('index'));
        $this->assertTrue($reflection->isSubclassOf(\WeShop\Compliance\Controller\Frontend\Compliance\Consent::class));
    }

    public function testComplianceConsentSaveAliasExists(): void
    {
        $reflection = new \ReflectionClass(Save::class);

        $this->assertTrue(class_exists(Save::class));
        $this->assertTrue($reflection->hasMethod('index'));
        $this->assertTrue($reflection->hasMethod('post'));
        $this->assertTrue($reflection->isSubclassOf(\WeShop\Compliance\Controller\Frontend\Consent\Save::class));
    }
}
