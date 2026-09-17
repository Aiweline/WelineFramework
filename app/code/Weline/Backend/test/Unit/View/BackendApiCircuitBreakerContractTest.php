<?php

declare(strict_types=1);

namespace Weline\Backend\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Backend Api/worker：redirect 标 http_redirect + 同 URL 熔断 + HTML 表单护栏。
 */
final class BackendApiCircuitBreakerContractTest extends TestCase
{
    public function testWorkerMarksRedirectAsHttpRedirectWithLocation(): void
    {
        $worker = (string)\file_get_contents(
            BP . 'app/code/Weline/Backend/view/statics/js/weline-api-worker.js'
        );

        self::assertStringContainsString("redirectError.code = 'http_redirect'", $worker);
        self::assertStringContainsString('redirectError.location = location', $worker);
        self::assertStringContainsString('body.code = error.code', $worker);
        self::assertStringContainsString('body.location = error.location', $worker);
    }

    public function testClientCircuitAndHtmlFormGuard(): void
    {
        $source = (string)\file_get_contents(
            BP . 'app/code/Weline/Backend/view/statics/js/weline-api.js'
        );

        self::assertStringContainsString("code: 'http_redirect'", $source);
        self::assertStringContainsString('acquireRequestCircuit', $source);
        self::assertStringContainsString('releaseRequestCircuit', $source);
        self::assertStringContainsString('REQUEST_CIRCUIT_COOLDOWN_MS', $source);
        self::assertStringContainsString('isBackendHtmlFormUrl', $source);
        self::assertStringContainsString('allowHtmlForm', $source);
        self::assertStringContainsString('html_form_refused', $source);
        self::assertStringContainsString("X-Weline-Api", $source);
        self::assertStringContainsString('ensureApiJsonHeaders', $source);
        self::assertStringContainsString('websites\\/admin\\/website\\/(edit|add)', $source);
    }

    public function testDirectFetchRejectsHttpRedirectWithoutFollowing(): void
    {
        $source = (string)\file_get_contents(
            BP . 'app/code/Weline/Backend/view/statics/js/weline-api.js'
        );

        self::assertStringContainsString(
            "status === 301 || status === 302 || status === 303 || status === 307 || status === 308",
            $source
        );
        self::assertStringContainsString("code: 'http_redirect'", $source);
        self::assertStringContainsString("redirect: requestOptions.redirect || 'manual'", $source);
    }
}
