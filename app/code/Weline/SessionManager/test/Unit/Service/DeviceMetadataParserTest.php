<?php

declare(strict_types=1);

namespace Weline\SessionManager\Test\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Weline\SessionManager\Service\DeviceMetadataParser;

final class DeviceMetadataParserTest extends TestCase
{
    /**
     * @return iterable<string,array{0:string,1:string,2:string,3:string}>
     */
    public static function userAgents(): iterable
    {
        yield 'chrome mac' => [
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/537.36 Chrome/130.0.0.0 Safari/537.36',
            'Chrome 130',
            'macOS',
            (string)__('%{1} on %{2}', ['Chrome', 'macOS']),
        ];
        yield 'safari iphone' => [
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 Version/17.5 Mobile/15E148 Safari/604.1',
            'Safari 17',
            'iOS',
            (string)__('%{1} on %{2}', ['Safari', 'iOS']),
        ];
        yield 'edge windows' => [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0',
            'Edge 130',
            'Windows',
            (string)__('%{1} on %{2}', ['Edge', 'Windows']),
        ];
    }

    #[DataProvider('userAgents')]
    public function testParsesBrowserOperatingSystemAndSafeDisplayName(
        string $userAgent,
        string $browser,
        string $operatingSystem,
        string $deviceName,
    ): void {
        $metadata = (new DeviceMetadataParser())->parse($userAgent, '203.0.113.9');

        self::assertSame($browser, $metadata->browser);
        self::assertSame($operatingSystem, $metadata->operatingSystem);
        self::assertSame($deviceName, $metadata->deviceName);
        self::assertSame('203.0.113.9', $metadata->ipAddress);
    }

    public function testUnknownOrOversizedInputIsBounded(): void
    {
        $metadata = (new DeviceMetadataParser())->parse(str_repeat('x', 10000), str_repeat('9', 500));

        self::assertSame((string)__('Unknown browser'), $metadata->browser);
        self::assertSame((string)__('Unknown system'), $metadata->operatingSystem);
        self::assertSame((string)__('Unknown device'), $metadata->deviceName);
        self::assertLessThanOrEqual(64, strlen($metadata->ipAddress));
    }
}
