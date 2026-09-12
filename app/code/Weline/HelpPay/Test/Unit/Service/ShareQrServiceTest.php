<?php

declare(strict_types=1);

namespace Weline\HelpPay\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\HelpPay\Service\ShareQrService;

final class ShareQrServiceTest extends TestCase
{
    public function testPngDataUriContainsOnlyUrlPayloadShape(): void
    {
        if (!class_exists(\Endroid\QrCode\QrCode::class)) {
            self::markTestSkipped('endroid/qr-code not installed');
        }
        $svc = new ShareQrService();
        $uri = $svc->pngDataUri('https://demo.test.weline.com/h/' . str_repeat('x', 24));
        self::assertStringStartsWith('data:image/png;base64,', $uri);
        self::assertGreaterThan(100, strlen($uri));
    }

    public function testRejectsNonHttpUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ShareQrService())->pngDataUri('javascript:alert(1)');
    }
}
