<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;

/**
 * OAuth connect must dispatch to OauthCapable Provider and RedirectException to vendor URL.
 */
final class OauthConnectRedirectContractTest extends TestCase
{
    public function testPostConnectThrowsRedirectExceptionForProviderUrl(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Controller/Backend/Oauth.php'
        );

        self::assertStringContainsString('use Weline\\Framework\\Http\\RedirectException;', $source);
        self::assertStringContainsString('use Weline\\Framework\\Http\\ResponseTerminateException;', $source);
        self::assertStringContainsString('use Weline\\Cdn\\Api\\OauthCapableProviderInterface;', $source);
        self::assertStringContainsString('startOauthAuthorization(', $source);
        self::assertStringContainsString('getOauthCapableAdapter(', $source);
        self::assertStringContainsString('throw new RedirectException($authorizationUrl, 302);', $source);
        self::assertStringContainsString('catch (ResponseTerminateException $terminate)', $source);
        self::assertStringContainsString('oauthFailureQuery', $source);
        self::assertStringContainsString('oauth_adapter', $source);
        self::assertStringContainsString('oauth_cf_error', $source);
        self::assertStringNotContainsString('->redirect($authorizationUrl)', $source);
        self::assertStringNotContainsString('CloudflareOAuthService', $source);
        self::assertStringNotContainsString('Cloudflare OAuth 授权已取消', $source);
    }
}
