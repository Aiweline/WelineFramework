<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

/**
 * Cloudflare v4 业务请求必须统一走 CloudflareHttpClient::apiWithCredentials
 *（Token Bearer + Global API Key），禁止 Adapter/业务再自写鉴权 curl。
 */
final class CloudflareUnifiedAuthTransportContractTest extends TestCase
{
    public function testAdapterMakeRequestDelegatesToHttpClient(): void
    {
        $src = (string)file_get_contents(BP . 'app/code/Weline/Cdn/Adapter/Cloudflare.php');
        self::assertStringContainsString('CloudflareHttpClient::class', $src);
        self::assertStringContainsString('apiWithCredentials', $src);
        self::assertStringContainsString('统一走 CloudflareHttpClient', $src);
        self::assertStringNotContainsString('curl_init($url)', $src);
    }

    public function testHttpClientIsSoleAuthHeaderBuilderConsumerForApi(): void
    {
        $http = (string)file_get_contents(BP . 'app/code/Weline/Cdn/Service/CloudflareHttpClient.php');
        self::assertStringContainsString('function apiWithCredentials', $http);
        self::assertStringContainsString('Cloudflare::buildAuthHeaders', $http);
        self::assertStringContainsString('Cloudflare::hasUsableCredentials', $http);
    }

    public function testRegistrarUsesUnifiedClientWhenCdnPresent(): void
    {
        $src = (string)file_get_contents(
            BP . 'app/code/Weline/Websites/Adapter/CloudflareRegistrar.php'
        );
        self::assertStringContainsString('CloudflareHttpClient::class', $src);
        self::assertStringContainsString('hasUsableCredentials', $src);
        self::assertStringContainsString('apiWithCredentials', $src);
        self::assertStringContainsString('normalizeCloudflareCredentials', $src);
        self::assertStringContainsString('buildFallbackAuthHeaders', $src);
        self::assertStringContainsString('resolveFallbackAuthMode', $src);
        self::assertStringContainsString('X-Auth-Key:', $src);
    }
}
