<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;

/**
 * 观测头瘦身产品契约：闸门接线不得回退；写点必须走 Policy。
 */
final class ResponseObservabilityLeanContractTest extends TestCase
{
    public function testProcessTimingInjectorsCallPolicy(): void
    {
        $http = \file_get_contents(BP . 'app/code/Weline/Server/bin/worker_http_message.php');
        $ssl = \file_get_contents(BP . 'app/code/Weline/Server/bin/worker_ssl_event.php');
        self::assertIsString($http);
        self::assertIsString($ssl);
        self::assertStringContainsString(
            'ResponseObservabilityPolicy::processTimingHeadersEnabled()',
            $http
        );
        self::assertStringContainsString(
            'ResponseObservabilityPolicy::processTimingHeadersEnabled()',
            $ssl
        );
    }

    public function testWorkerIdentityHeadersCallPolicy(): void
    {
        $worker = \file_get_contents(BP . 'app/code/Weline/Server/bin/worker.php');
        self::assertIsString($worker);
        self::assertStringContainsString(
            'ResponseObservabilityPolicy::identityHeadersEnabled()',
            $worker
        );
    }

    public function testRedirectCookieDiagHeaderIsGated(): void
    {
        $runtime = \file_get_contents(BP . 'app/code/Weline/Framework/Runtime/WlsRuntime.php');
        self::assertIsString($runtime);
        $headerPos = \strpos($runtime, "setHeader('X-WLS-Redirect-Cookies'");
        self::assertNotFalse($headerPos);
        $window = \substr($runtime, \max(0, $headerPos - 240), 240);
        self::assertStringContainsString(
            'ResponseObservabilityPolicy::performanceBreakdownEnabled()',
            $window,
            'Redirect-Cookies 写头前 240 字符内必须有 Policy 闸门'
        );
    }

    public function testAdminPerfHeadersCallPolicy(): void
    {
        $admin = \file_get_contents(BP . 'app/code/Weline/Admin/Api/Controller/BaseController.php');
        self::assertIsString($admin);
        self::assertStringContainsString('ResponseObservabilityPolicy::performanceBreakdownEnabled()', $admin);
        self::assertStringContainsString('ResponseObservabilityPolicy::dynamicObservabilityEnabled()', $admin);
    }

    public function testResponsePoweredByIsGated(): void
    {
        $response = \file_get_contents(BP . 'app/code/Weline/Framework/Http/Response.php');
        self::assertIsString($response);
        self::assertStringContainsString(
            'ResponseObservabilityPolicy::poweredByHeaderEnabled()',
            $response
        );
    }

    public function testFpcHitPerformanceHelperIsGated(): void
    {
        $coord = \file_get_contents(BP . 'app/code/Weline/Framework/Router/FullPageCacheCoordinator.php');
        self::assertIsString($coord);
        self::assertStringContainsString(
            'ResponseObservabilityPolicy::performanceBreakdownEnabled()',
            $coord
        );
        self::assertStringContainsString('function applyFpcHitPerformanceHeaders', $coord);
    }

    public function testPolicyDocumentsPublicAllowlist(): void
    {
        $policy = \file_get_contents(BP . 'app/code/Weline/Framework/Http/ResponseObservabilityPolicy.php');
        self::assertIsString($policy);
        self::assertStringContainsString('x-weline-request-id', $policy);
        self::assertStringContainsString('x-weline-fpc', $policy);
        self::assertStringContainsString('x-wls-fpc-status', $policy);
        self::assertStringContainsString('processTimingHeadersEnabled', $policy);
    }
}
