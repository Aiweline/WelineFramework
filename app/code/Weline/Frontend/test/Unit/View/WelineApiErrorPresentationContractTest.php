<?php

declare(strict_types=1);

namespace Weline\Frontend\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class WelineApiErrorPresentationContractTest extends TestCase
{
    public function testWorkerAttachesStructuredProtocolErrorDetails(): void
    {
        $script = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/view/statics/js/weline-api-worker.js',
        );
        self::assertStringContainsString("code = 'origin_unreachable'", $script);
        self::assertStringContainsString("code = 'unexpected_html'", $script);
        self::assertStringContainsString("code = 'wqb_invalid_magic'", $script);
        self::assertStringContainsString('details,', $script);
        self::assertStringContainsString('responseKind: desc.kind', $script);
        self::assertStringContainsString("details: error && error.details ? String(error.details) : ''", $script);
    }

    public function testWorkerRetriesTransientGatewayFailuresWithoutResettingSession(): void
    {
        $script = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/view/statics/js/weline-api-worker.js',
        );
        self::assertStringContainsString('function isTransientGatewayResult(result)', $script);
        self::assertStringContainsString('gatewayAttempt < 2', $script);
        self::assertStringContainsString("code: 'service_unavailable'", $script);
        self::assertStringContainsString('noteHandshakeSoftBackoff()', $script);
        self::assertStringContainsString('response.status === 502 || response.status === 503 || response.status === 504', $script);
    }

    public function testApiPresentsFriendlyToastWithExpandableDetails(): void
    {
        $script = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/view/statics/js/weline-api.js',
        );
        self::assertStringContainsString('resolveErrorPresentation', $script);
        self::assertStringContainsString('origin_unreachable', $script);
        self::assertStringContainsString("i18nText('源站暂时不可用')", $script);
        self::assertStringContainsString("i18nText('查看详情')", $script);
        self::assertStringContainsString('toastOptions', $script);
        self::assertStringContainsString('details: presentation.details', $script);
        self::assertStringContainsString('error.details = serverError.details', $script);
    }

    public function testApiSilentTransientFailuresDoNotFanOutOrResetWorker(): void
    {
        $script = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/view/statics/js/weline-api.js',
        );
        self::assertStringContainsString('isTransientServiceError(error)', $script);
        self::assertStringContainsString('meta && meta.silent', $script);
        self::assertStringContainsString('silentStatus === 502', $script);
        self::assertStringContainsString('without resetting the Worker', $script);
    }

    public function testApiScopeConflictCodesAutoReloadOnce(): void
    {
        $script = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/view/statics/js/weline-api.js',
        );
        self::assertStringContainsString('request_scope_already_conflicts', $script);
        self::assertStringContainsString('scope_context_conflict', $script);
        self::assertStringContainsString('scope_reload_required', $script);
        self::assertStringContainsString('autoReload: true', $script);
        self::assertStringContainsString('maybeAutoReloadForScopeConflict', $script);
        self::assertStringContainsString('weline.api.scope_reload_at', $script);
        self::assertStringContainsString('window.location.reload()', $script);
        // Main-thread client must adopt the new page bootstrap (not throw sticky 409).
        self::assertStringContainsString(
            'client.config.scopeBootstrapId = freshConfig.scopeBootstrapId;',
            $script,
        );
        self::assertStringNotContainsString(
            "error.code = 'scope_context_conflict';\n            throw error;",
            $script,
        );
    }

    public function testWorkerRehandshakesWhenScopeBootstrapChanges(): void
    {
        $script = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/view/statics/js/weline-api-worker.js',
        );
        self::assertStringContainsString('SharedWorker survives full navigations', $script);
        self::assertStringContainsString('workerScopeBootstrapId !== config.scopeBootstrapId', $script);
        self::assertStringContainsString("workerScopeBootstrapId = '';", $script);
        self::assertStringNotContainsString(
            "throw Object.assign(new Error('The page Worker bootstrap changed while this worker was active.')",
            $script,
        );
    }
}
