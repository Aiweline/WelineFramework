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
        self::assertStringContainsString('scope_binding_required', $script);
        self::assertStringContainsString('deploy_reload_required', $script);
        self::assertStringContainsString("i18nText('站点已更新')", $script);
        self::assertStringContainsString("i18nText('商城范围未就绪')", $script);
        self::assertStringContainsString('autoReload: true', $script);
        self::assertStringContainsString('maybeAutoReloadForScopeConflict', $script);
        self::assertStringContainsString('weline.api.scope_reload_at', $script);
        self::assertStringContainsString('weline.api.scope_tips_by_locale', $script);
        self::assertStringContainsString('rememberScopeTipsFromPayload', $script);
        self::assertStringContainsString('readScopeTipCache', $script);
        self::assertStringContainsString('writeScopeTipCache', $script);
        self::assertStringContainsString('error.tip = normalizeScopeTip(serverError.tip)', $script);
        self::assertStringContainsString('window.location.reload()', $script);
        // autoReload must run before silent / onHttpError short-circuit.
        self::assertStringContainsString(
            'presentation.autoReload && maybeAutoReloadForScopeConflict(presentation)',
            $script,
        );
        // Main-thread client must adopt Scope ids on channel/store navigations.
        self::assertStringContainsString(
            'client.config.website_id = freshConfig.website_id;',
            $script,
        );
        self::assertStringContainsString(
            'client.config.store_id = freshConfig.store_id;',
            $script,
        );
        self::assertStringContainsString(
            'client.config.channel_id = freshConfig.channel_id;',
            $script,
        );
        self::assertStringNotContainsString(
            "error.code = 'scope_context_conflict';\n            throw error;",
            $script,
        );
        // Stamp expiry recovers in-page (re-punch + retry); only identity/theme drift reloads.
        self::assertStringContainsString('isScopeStampRecoverableError', $script);
        self::assertStringContainsString('recoverScopeStamp', $script);
        self::assertStringContainsString('__scopeStampRetry', $script);
        self::assertStringContainsString('forceHandshake: forceHandshake', $script);
        self::assertStringContainsString("i18nText('当前页面商城范围已过期，正在重新签章以继续。')", $script);
        self::assertStringContainsString("i18nText('页面绑定信息缺失或已失效，正在重新签章以继续。')", $script);
        self::assertStringNotContainsString(
            "message: i18nText('当前页面商城范围已过期，正在刷新以继续。'),\n                autoReload: true,",
            $script,
        );
        self::assertStringNotContainsString(
            "message: i18nText('页面绑定信息缺失或已失效，正在刷新以继续。'),\n                autoReload: true,",
            $script,
        );
    }

    public function testWorkerReusesSessionByScopeCanonicalKey(): void
    {
        $script = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/view/statics/js/weline-api-worker.js',
        );
        self::assertStringContainsString('SharedWorker survives full navigations', $script);
        self::assertStringContainsString('sessionsByScopeKey', $script);
        self::assertStringContainsString('function scopeSessionIdentityKey(config)', $script);
        self::assertStringContainsString('buildScopeCanonicalFromIds', $script);
        self::assertStringContainsString('Must NOT use Host+path', $script);
        // Stamp recover forces SharedWorker to drop badge + re-handshake after Cookie re-punch.
        self::assertStringContainsString('forceHandshake', $script);
        self::assertStringContainsString('async function ensureSession(config, options = {})', $script);
        self::assertStringContainsString("code === 'scope_binding_required'", $script);
        // Storefront handshake must not send one-shot opaque bootstrap id.
        self::assertStringContainsString('never send scope_bootstrap_id', $script);
        self::assertStringNotContainsString('handshakePayload.scope_bootstrap_id', $script);
        self::assertStringNotContainsString('sessionsByUrlKey', $script);
        self::assertStringNotContainsString(
            "throw Object.assign(new Error('The page Worker bootstrap changed while this worker was active.')",
            $script,
        );
    }

    public function testApiWaitsForScopeBootstrapWithSoftPleaseWaitTip(): void
    {
        $script = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/view/statics/js/weline-api.js',
        );
        self::assertStringContainsString('notifyScopeBootstrapWaiting', $script);
        self::assertStringContainsString('renderScopeWaitTip', $script);
        self::assertStringContainsString("i18nText('请稍等片刻...')", $script);
        // Soft tip: Toast success ⇒ stop; fallback only when shown is false (no Theme DOM probe).
        self::assertStringContainsString('if (!shown) {', $script);
        self::assertStringContainsString('this.renderScopeWaitTip(message);', $script);
        self::assertStringNotContainsString(
            "[data-theme-notice-region] .weline-notice-toast",
            $script,
        );
        self::assertStringContainsString('SCOPE_HOLE_PUNCH_TIMEOUT_MS', $script);
        self::assertStringContainsString("timedOut.code = 'scope_bootstrap_timeout'", $script);
        self::assertStringContainsString('scope_bootstrap_timeout:', $script);
        // Tip waits for first long-visa ensure — not opaque bootstrap punching.
        self::assertStringContainsString('Not "still punching opaque bootstrap"', $script);
        self::assertStringContainsString('isScopeStampAlreadyEnsured', $script);
        self::assertStringContainsString('SCOPE_STAMP_MEMORY_KEY', $script);
        self::assertStringContainsString('Authorized long Scope Cookie path', $script);
        self::assertStringContainsString('const ensureScopeStamp = (ids) => {', $script);
        // Cross-page: ensure must honor sessionStorage before fetch (not page-memory only).
        self::assertStringContainsString('if (isScopeStampAlreadyEnsured(ids)) {', $script);
        self::assertStringContainsString('rememberScopeStampKey(stampKey, expiresAt)', $script);
        self::assertStringContainsString('SCOPE_STAMP_SKEW_MS', $script);
        self::assertStringContainsString('isScopeStampFresh', $script);
        self::assertStringContainsString('scheduleScopeStampRenewal', $script);
        self::assertStringContainsString('bindScopeStampVisibilityRenew', $script);
        self::assertStringContainsString('scopeStampEnsuredExpiresAt', $script);
        // 204 / rollout-off must not invent a long soft TTL visa.
        self::assertStringContainsString('no_stamp: true', $script);
        self::assertStringNotContainsString('SCOPE_STAMP_SOFT_TTL_SEC', $script);
        self::assertStringContainsString('isScopeBindingMissingError', $script);
        // Binding miss must drop optimistic memory (Reload otherwise loops forever).
        self::assertStringContainsString('forgetScopeStampKey();', $script);
        self::assertStringContainsString('Drop optimistic stamp memory before reload', $script);
        self::assertStringContainsString('allowRepunch && isScopeStampRecoverableError(error)', $script);
        self::assertStringContainsString('warmup({ forceHandshake: true })', $script);
        self::assertStringContainsString('shouldDeferScopeStampErrorUi', $script);
        self::assertStringContainsString('Success does not require bootstrap_id', $script);
        self::assertStringNotContainsString('SCOPE_BOOTSTRAP_OPAQUE', $script);
        self::assertStringNotContainsString('readScopeBootstrapId', $script);
        // Non-bootstrap QueryBin must queue behind warmup; tip only when stamp not yet ensured.
        self::assertStringContainsString('!isScopeStampAlreadyEnsured(this.config)', $script);
        self::assertStringContainsString('notifyScopeBootstrapWaiting()', $script);
        self::assertStringContainsString('return this.warmup().then(() => this.sendToWorker(payload));', $script);
        // Eager warmup even without meta (FPC stamp ensure).
        self::assertStringContainsString('ApiModule.bootstrapScope().catch((error) => {', $script);
        self::assertStringNotContainsString(
            "if (readScopeBootstrapId() !== '') {\n            ApiModule.bootstrapScope()",
            $script,
        );
        // Full module must always promote onto Weline.Api (stub must not stick).
        self::assertStringContainsString('window.Weline.Api = ApiModule;', $script);
        self::assertStringContainsString(
            'Always promote — stub must not remain if full module loaded',
            $script,
        );
    }

    public function testWelineJsPreservesFullApiAcrossInit(): void
    {
        $script = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/view/statics/js/weline.js',
        );
        self::assertStringContainsString('window.Weline.Api.__full === true', $script);
        self::assertStringContainsString('_promoteFullApi', $script);
        self::assertStringContainsString('bootstrapScope: async () => {', $script);
        self::assertStringContainsString(
            'never leave a stub on top of an already-full WelineApiModule',
            $script,
        );
    }
}
