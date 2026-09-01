<?php

declare(strict_types=1);

namespace Weline\Affiliate\Test\Unit\Controller\Backend\Affiliate;

use PHPUnit\Framework\TestCase;
use Weline\Affiliate\Controller\Backend\Affiliate\Save;

class SaveTest extends TestCase
{
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(Save::class));
    }

    public function testControllerHasPostAndIndexMethods(): void
    {
        $reflection = new \ReflectionClass(Save::class);
        $this->assertTrue($reflection->hasMethod('post'));
        $this->assertTrue($reflection->hasMethod('index'));
    }

    public function testCommissionRateRequestDefaultPreservesDecimals(): void
    {
        $reflection = new \ReflectionClass(Save::class);
        $content = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($content);
        $this->assertStringContainsString("getParam('commission_rate', 0.0)", $content);
        $this->assertStringContainsString("getParam('website_id', 0)", $content);
        $this->assertStringContainsString("getParam('store_code', '')", $content);
        $this->assertStringContainsString("getParam('channel_code', '')", $content);
    }

    public function testRedirectIsOutsideThrowableCatchSo302IsNotFlashedAsError(): void
    {
        $reflection = new \ReflectionClass(Save::class);
        $content = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($content);
        $this->assertMatchesRegularExpression(
            '/catch\s*\(\s*\\\\Throwable\s+\$throwable\s*\)\s*\{[\s\S]*?\}\s*\n\s*\$this->redirect\(\$backUrl\);/m',
            $content
        );
        $this->assertDoesNotMatchRegularExpression(
            '/try\s*\{[\s\S]*?\$this->redirect\(\$backUrl\);[\s\S]*?\}\s*catch\s*\(\s*\\\\Throwable/m',
            $content
        );
    }
}
