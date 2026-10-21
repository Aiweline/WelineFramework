<?php

declare(strict_types=1);

namespace Weline\Frontend\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Weline\Frontend\Helper\FrontendScopeReloadTip;

final class FrontendScopeReloadTipTest extends TestCase
{
    public function testTipCodesCoverScopeAndDeployReload(): void
    {
        self::assertTrue(FrontendScopeReloadTip::isTipCode('deploy_reload_required'));
        self::assertTrue(FrontendScopeReloadTip::isTipCode('scope_reload_required'));
        self::assertTrue(FrontendScopeReloadTip::isTipCode('scope_binding_required'));
        self::assertTrue(FrontendScopeReloadTip::isTipCode('scope_context_conflict'));
        self::assertTrue(FrontendScopeReloadTip::isTipCode('request_scope_already_conflicts'));
        self::assertTrue(FrontendScopeReloadTip::isTipCode('scope_token_expired'));
        self::assertTrue(FrontendScopeReloadTip::isTipCode('scope_token_context_conflict'));
        self::assertTrue(FrontendScopeReloadTip::isTipCode('scope_bootstrap_waiting'));
        self::assertFalse(FrontendScopeReloadTip::isTipCode('protocol_error'));
    }

    public function testClientRePunchesScopeTokenBeforeGuardedReload(): void
    {
        $script = (string)file_get_contents(dirname(__DIR__, 3) . '/view/statics/js/weline-api.js');
        self::assertMatchesRegularExpression(
            "/const isScopeStampRecoverableError = \(error\) => \{[\s\S]*?code === 'scope_token_expired'[\s\S]*?code === 'scope_token_context_conflict'[\s\S]*?\n    \};/",
            $script,
        );
        self::assertDoesNotMatchRegularExpression(
            "/const isScopeStampRecoverableError = \(error\) => \{[\s\S]*?scope_token_invalid[\s\S]*?\n    \};/",
            $script,
        );
        self::assertMatchesRegularExpression(
            "/scope_token_expired: \{[\s\S]*?autoReload: true,/",
            $script,
        );
        self::assertMatchesRegularExpression(
            "/scope_token_context_conflict: \{[\s\S]*?autoReload: true,/",
            $script,
        );
    }

    public function testScopeBootstrapWaitingUsesPleaseWaitPhrase(): void
    {
        $tip = FrontendScopeReloadTip::build('scope_bootstrap_waiting');
        self::assertNotNull($tip);
        self::assertNotSame('', (string)$tip['message']);
        self::assertSame((string)$tip['title'], (string)$tip['message']);
    }

    public function testBuildReturnsLocalizedTipShape(): void
    {
        $tip = FrontendScopeReloadTip::build('deploy_reload_required');
        self::assertNotNull($tip);
        self::assertArrayHasKey('title', $tip);
        self::assertArrayHasKey('message', $tip);
        self::assertArrayHasKey('locale', $tip);
        self::assertNotSame('', (string)$tip['title']);
        self::assertNotSame('', (string)$tip['message']);
        self::assertNotSame('', (string)$tip['locale']);
    }

    public function testCatalogForCurrentLocaleCoversAllTipCodes(): void
    {
        $catalog = FrontendScopeReloadTip::catalogForCurrentLocale();
        foreach (FrontendScopeReloadTip::TIP_CODES as $code) {
            self::assertArrayHasKey($code, $catalog);
            self::assertNotSame('', (string)$catalog[$code]['title']);
            self::assertNotSame('', (string)$catalog[$code]['message']);
        }
    }

    public function testQueryBinAttachesTipOnFrontendQueryException(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Api/QueryBin.php',
        );
        self::assertStringContainsString('FrontendScopeReloadTip::build', $src);
        self::assertStringContainsString("\$errorPayload['tip'] = \$tip", $src);
    }

    public function testScopeBootstrapWarmsTipsCatalog(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Api/ScopeBootstrap.php',
        );
        self::assertStringContainsString('FrontendScopeReloadTip::catalogForCurrentLocale', $src);
        self::assertStringContainsString("'tips' =>", $src);
        self::assertStringContainsString("'tips_locale' =>", $src);
        self::assertStringContainsString('armRequestLocaleFromPagePath', $src);
        self::assertStringContainsString('setRequestLanguageOverride', $src);
    }
}
