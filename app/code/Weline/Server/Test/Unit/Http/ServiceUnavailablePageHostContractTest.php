<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Server\Http\ServiceUnavailablePage;

final class ServiceUnavailablePageHostContractTest extends TestCase
{
    public function testHtmlBodyPassesHostIntoMaintenanceStaticPage(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Http/ServiceUnavailablePage.php'
        );

        self::assertStringContainsString("headers['host']", $source);
        self::assertStringContainsString('loadHtml(', $source);
        self::assertStringContainsString('loadJson(', $source);
        // Host must be forwarded for website×locale zero-DB selection.
        self::assertMatchesRegularExpression(
            '/loadHtml\([\s\S]*headers\[.host.\].*\)/m',
            $source
        );
        self::assertMatchesRegularExpression(
            '/loadJson\([\s\S]*headers\[.host.\].*\)/m',
            $source
        );
    }

    public function testHttpResponseAcceptsHostHeaderWithoutThrowing(): void
    {
        $response = ServiceUnavailablePage::httpResponse(
            ServiceUnavailablePage::VARIANT_MAINTENANCE,
            false,
            '',
            5,
            ['host' => 'shop.example.com', 'accept' => 'text/html'],
            '/store-a/en_US/',
        );

        self::assertStringContainsString('HTTP/1.1 503', $response);
        self::assertStringContainsString('text/html', $response);
    }
}
