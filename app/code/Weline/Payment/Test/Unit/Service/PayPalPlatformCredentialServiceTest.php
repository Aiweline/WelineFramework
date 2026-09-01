<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class PayPalPlatformCredentialServiceTest extends TestCase
{
    private string $bundledFile;

    protected function setUp(): void
    {
        $this->bundledFile = dirname(__DIR__, 3) . '/etc/platform/paypal.sandbox.bundled.php';
    }

    public function testBundledSandboxCredentialsFileExists(): void
    {
        self::assertFileExists($this->bundledFile);
    }

    public function testLoadBundledSandboxCredentialsReturnsArrayShape(): void
    {
        $data = require $this->bundledFile;
        self::assertIsArray($data);
        self::assertArrayHasKey('client_id', $data);
        self::assertArrayHasKey('client_secret', $data);
        self::assertIsString($data['client_id']);
        self::assertIsString($data['client_secret']);
    }
}
