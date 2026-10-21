<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Server\Http\ServiceUnavailablePage;

final class ServiceUnavailablePageTest extends TestCase
{
    public function testMaintenanceHttpResponseUsesFrameworkHtmlNotPlainText(): void
    {
        $response = ServiceUnavailablePage::httpResponse(ServiceUnavailablePage::VARIANT_MAINTENANCE);

        self::assertStringContainsString('HTTP/1.1 503 Service Unavailable', $response);
        self::assertStringContainsString('Content-Type: text/html; charset=UTF-8', $response);
        // The body may come from a published maintenance snapshot in any
        // configured language; this contract only proves framework HTML (not
        // the old plain-text 503) is returned.
        self::assertStringContainsString('<!DOCTYPE html', $response);
        self::assertMatchesRegularExpression('/<h1[^>]*>.+<\/h1>/u', $response);
        self::assertStringNotContainsString("\r\n\r\nService Unavailable", $response);
    }

    public function testStartupHttpResponseUsesFriendlyStartupHtml(): void
    {
        $response = ServiceUnavailablePage::httpResponse(ServiceUnavailablePage::VARIANT_STARTUP);

        self::assertStringContainsString('WLS正在启动中', $response);
        self::assertStringContainsString('Content-Type: text/html; charset=UTF-8', $response);
    }

    public function testJsonAcceptReturnsStructuredMaintenancePayload(): void
    {
        $response = ServiceUnavailablePage::httpResponse(
            ServiceUnavailablePage::VARIANT_MAINTENANCE,
            headers: ['accept' => 'application/json'],
            path: '/api/v1/ping',
        );

        self::assertStringContainsString('Content-Type: application/json; charset=utf-8', $response);
        $body = \substr($response, (int)\strpos($response, "\r\n\r\n") + 4);
        $payload = \json_decode($body, true);
        self::assertIsArray($payload);
        self::assertSame('maintenance', $payload['code'] ?? null);
        self::assertStringContainsString('X-Weline-Maintenance: 1', $response);
        self::assertStringContainsString('Set-Cookie: weline_mw_gate=', $response);
        self::assertStringNotContainsString('<html', $response);
    }

    public function testStartupHttpResponseDoesNotMintWaitGiftGateCookie(): void
    {
        $response = ServiceUnavailablePage::httpResponse(ServiceUnavailablePage::VARIANT_STARTUP);

        self::assertStringNotContainsString('weline_mw_gate=', $response);
        self::assertStringNotContainsString('X-Weline-Maintenance: 1', $response);
    }

    public function testMaintenanceHtmlUsesPathLanguageStaticSnapshot(): void
    {
        if (!\defined('BP')) {
            self::markTestSkipped('BP not defined');
        }

        (new \Weline\Maintenance\Service\MaintenanceStaticGenerator())->publishAll(60);

        $response = ServiceUnavailablePage::httpResponse(
            ServiceUnavailablePage::VARIANT_MAINTENANCE,
            path: '/en_US/products',
        );

        self::assertStringContainsString('System Upgrade in Progress', $response);
        self::assertStringNotContainsString('系统升级维护中', $response);
    }
}
