<?php

declare(strict_types=1);

namespace Weline\Frontend\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;

/**
 * Storefront drift recovery must stop by itself.
 *
 * The old policy was "re-punch, then reload with an 8s throttle": any persistent
 * Scope/generation conflict reloaded the page forever. These assertions lock the
 * replacement contract: one automatic attempt per (page, generation), never a reload
 * for Scope-credential drift, and a persistent user-driven exit afterwards.
 */
final class FrontendScopeReloadPolicyTest extends TestCase
{
    private static function apiScript(): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/view/statics/js/weline-api.js');
    }

    private static function welineScript(): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/view/statics/js/weline.js');
    }

    public function testLedgerReplacesGlobalReloadTimestamp(): void
    {
        $script = self::apiScript();
        self::assertStringContainsString("SCOPE_RELOAD_LEDGER_KEY = 'weline.api.scope_reload_ledger'", $script);
        self::assertStringContainsString('SCOPE_RELOAD_COOLDOWN_MS = 30000', $script);
        self::assertStringNotContainsString('weline.api.scope_reload_at', $script);
    }

    public function testSameGenerationIsAttemptedOnlyOnce(): void
    {
        $script = self::apiScript();
        // Ledger key = host + path + kind + target, and the ledger is consulted BEFORE
        // any navigation is started.
        self::assertStringContainsString('const scopeReloadTargetKey = (kind, target) => {', $script);
        self::assertStringContainsString('return [host, path, kind, String(target || \'\')].join(\'\\u0000\');', $script);
        $consultPos = \strpos($script, 'if (ledger[key] != null || (lastAt > 0 && (now - lastAt) < SCOPE_RELOAD_COOLDOWN_MS)) {');
        $ledgerWritePos = \strpos($script, 'ledger[key] = now;');
        self::assertIsInt($consultPos);
        self::assertIsInt($ledgerWritePos);
        self::assertLessThan($ledgerWritePos, $consultPos);
    }

    public function testScopeDriftGetsOneReloadKeyedByRequestScope(): void
    {
        $script = self::apiScript();
        self::assertMatchesRegularExpression(
            "/const scopeDriftKind = \(code\) => \(code === 'deploy_reload_required' \? 'generation' : 'scope'\);/",
            $script,
        );
        // The Scope the server resolved to is the reload target: same tuple ⇒ never twice.
        self::assertStringContainsString('readErrorRequestScopeTag(sourceError) || code', $script);
        $ledgerWritePos = \strpos($script, 'writeScopeReloadLedger(ledger);');
        $plainReloadPos = \strpos($script, "if (kind !== 'generation' || pageGenerationToken === '') {");
        self::assertIsInt($ledgerWritePos);
        self::assertIsInt($plainReloadPos);
        self::assertLessThan($plainReloadPos, $ledgerWritePos);
    }

    public function testUnsavedInputBlocksAutomaticNavigation(): void
    {
        $script = self::apiScript();
        self::assertStringContainsString('const hasUnsavedUserInput = () => {', $script);
        $guardPos = \strpos($script, 'if (hasUnsavedUserInput()) {');
        $ledgerWritePos = \strpos($script, 'ledger[key] = now;');
        self::assertIsInt($guardPos);
        self::assertIsInt($ledgerWritePos);
        self::assertLessThan($ledgerWritePos, $guardPos);
    }

    public function testTerminalExitIsUserDriven(): void
    {
        $script = self::apiScript();
        self::assertStringContainsString("el.setAttribute('role', 'alert');", $script);
        self::assertStringContainsString("button.addEventListener('click', () => {", $script);
        self::assertStringContainsString('window.Weline.navigateToRequestedPageGeneration', $script);
        // A recovered page must not keep claiming that an update is required.
        self::assertStringContainsString('clearScopeDriftTerminal();', $script);
    }

    public function testNavigationRefusesToReloadAnUnchangedTarget(): void
    {
        $welineJs = self::welineScript();
        // Empty / already-current generation ⇒ no navigation at all (reloading the same
        // URL re-requests the document that just failed).
        self::assertMatchesRegularExpression(
            "/function navigateToRequestedPageGeneration\(pageGenerationToken\) \{[\s\S]*?if \(!\/\^\[a-f0-9\]\{16\}\\$\/\.test\(gen\)\) \{\s*return false;\s*\}/",
            $welineJs,
        );
        self::assertMatchesRegularExpression(
            "/if \(url\.searchParams\.get\('w_pg'\) === gen\) \{[\s\S]*?return false;\s*\}/",
            $welineJs,
        );
        self::assertStringContainsString(
            'Weline.navigateToRequestedPageGeneration = navigateToRequestedPageGeneration;',
            $welineJs,
        );
    }

    public function testServedGenerationDropsPinnedWpg(): void
    {
        $script = self::apiScript();
        self::assertStringContainsString('dropServedPageGenerationQuery(payload.page_generation_token);', $script);
    }

    /**
     * The browser can only fetch another generation when the server names it: every
     * Scope/generation 409 owes us `page_generation_token` — not just deploy reloads.
     */
    public function testServerNamesTheGenerationForEveryScopeDriftFailure(): void
    {
        $controller = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Api/QueryBin.php',
        );
        self::assertStringContainsString("if (\$this->isStorefrontDriftCode(\$errorCode)) {", $controller);
        self::assertStringContainsString("\$errorPayload['page_generation_token'] = \\Weline\\Framework\\Http\\Fpc\\FpcHtmlDiskStore::pageGenerationToken();", $controller);
        self::assertStringContainsString("'scope_token_context_conflict',", $controller);
        self::assertStringContainsString("'scope_token_expired',", $controller);
        self::assertStringContainsString("'request_scope_already_conflicts',", $controller);
        self::assertStringContainsString("'deploy_reload_required',", $controller);
    }
}
