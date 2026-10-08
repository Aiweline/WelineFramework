<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

/**
 * 邮局 DNS 一键同步须支持 CDN 账户 Global API Key（邮箱+密钥），不能只走 Bearer api_token。
 */
final class CloudflareMailDnsGlobalKeyContractTest extends TestCase
{
    public function testHttpClientExposesApiWithCredentials(): void
    {
        $http = (string)file_get_contents(BP . 'app/code/Weline/Cdn/Service/CloudflareHttpClient.php');
        self::assertStringContainsString('function apiWithCredentials', $http);
        self::assertStringContainsString('Cloudflare::buildAuthHeaders', $http);
        self::assertStringContainsString('Cloudflare::hasUsableCredentials', $http);
    }

    public function testMailDnsManagerUsesCredentialsNotBearerOnly(): void
    {
        $mgr = (string)file_get_contents(BP . 'app/code/Weline/Cdn/Service/CloudflareMailDnsManager.php');
        self::assertStringContainsString('apiWithCredentials', $mgr);
        self::assertStringContainsString('hasUsableCredentials', $mgr);
        self::assertStringNotContainsString(
            "\$token = trim((string)(\$credentials['api_token'] ?? ''));",
            $mgr
        );
    }

    public function testPlannerAllowsAbsentDkimDesiredRecord(): void
    {
        $src = (string)file_get_contents(BP . 'app/code/Weline/Cdn/Service/CloudflareMailDnsPlanner.php');
        self::assertStringContainsString("\$wanted === [] && \$specification['label'] === 'DKIM'", $src);
    }
}
