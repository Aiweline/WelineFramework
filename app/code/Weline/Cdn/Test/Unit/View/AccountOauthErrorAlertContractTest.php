<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Account page must keep OAuth bounce errors on-screen; scopes are tips, not on-page config.
 */
final class AccountOauthErrorAlertContractTest extends TestCase
{
    public function testIndexSurfacesCloudflareErrorCodesPersistently(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/Account/index.phtml'
        );

        self::assertStringContainsString('oauth_cf_error', $source);
        self::assertStringContainsString('oauth_adapter', $source);
        self::assertStringContainsString('data-testid="cdn-oauth-error"', $source);
        self::assertStringContainsString('invalid_scope', $source);
        self::assertStringContainsString('cdn-oauth-scopes-hint', $source);
        self::assertStringContainsString('cdn-oauth-scopes-gate-hint', $source);
        self::assertStringContainsString('cdn-oauth-redirect-uri-gate-toggle', $source);
        self::assertStringContainsString('data-w-disclosure-trigger', $source);
        self::assertStringContainsString('data-w-disclosure-panel', $source);
        self::assertStringContainsString('data-state="closed"', $source);
        self::assertStringContainsString('本页仅提示', $source);
        self::assertStringContainsString('getOauthCapableAdapters', $source);
        self::assertStringContainsString('name="adapter"', $source);
        self::assertStringContainsString('w-alert__content', $source);
        self::assertStringNotContainsString('data-testid="cdn-oauth-required-scopes"', $source);
        self::assertStringNotContainsString('data-testid="cdn-oauth-required-scopes-gate"', $source);
        self::assertStringNotContainsString('data-testid="cdn-oauth-connect-hint"', $source);
    }
}
