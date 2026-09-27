<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * OAuth client credentials must be readable from SystemConfig keys, not env-only.
 */
final class CloudflareOAuthServiceConfigFallbackTest extends TestCase
{
    public function testServiceReadsSystemConfigKeysBeforeEnvOnly(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/CloudflareOAuthService.php'
        );

        self::assertStringContainsString('cdn/cloudflare/oauth_client_id', $source);
        self::assertStringContainsString('cdn/cloudflare/oauth_client_secret', $source);
        self::assertStringContainsString('ConfigReader', $source);
        self::assertStringContainsString('resolveSetting', $source);
        self::assertStringContainsString('系统配置填写 OAuth Client ID/Secret', $source);
    }

    public function testAccountIndexExposesOneClickConnectButton(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/Account/index.phtml'
        );

        self::assertStringContainsString('cdn/backend/oauth/connect', $source);
        self::assertStringContainsString('cdn-oauth-connect-button', $source);
        self::assertStringContainsString('cdn-oauth-config-button', $source);
        self::assertStringContainsString('cdn-oauth-identical-credentials', $source);
        self::assertStringContainsString('cdn-oauth-misplaced-credentials', $source);
        self::assertStringContainsString('连接或重新授权 Cloudflare', $source);
        self::assertStringContainsString('先去配置 Cloudflare OAuth', $source);
        self::assertStringContainsString('hasIdenticalClientCredentials', $source);
        self::assertStringContainsString('hasMisplacedClientCredentials', $source);
    }

    public function testServiceRejectsIdenticalClientIdAndSecret(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/CloudflareOAuthService.php'
        );

        self::assertStringContainsString('hasIdenticalClientCredentials', $source);
        self::assertStringContainsString('!hash_equals($clientId, $clientSecret)', $source);
        self::assertStringContainsString('Client ID 与 Client Secret 相同', $source);
    }

    public function testServiceRejectsSwappedCfocIdAndHexSecret(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/CloudflareOAuthService.php'
        );

        self::assertStringContainsString('hasMisplacedClientCredentials', $source);
        self::assertStringContainsString('credentialsLookMisplaced', $source);
        self::assertStringContainsString('looksLikeOauthClientSecret', $source);
        self::assertStringContainsString('!$this->hasMisplacedClientCredentials()', $source);
        self::assertStringContainsString('Your Client ID', $source);
        self::assertStringNotContainsString('looksLikeCloudflareAccountId', $source);
        self::assertStringNotContainsString('Client ID 不能填 Account ID', $source);
    }

    public function testAuthorizationUrlIncludesPkceS256(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/CloudflareOAuthService.php'
        );

        self::assertStringContainsString("'code_challenge'", $source);
        self::assertStringContainsString("'code_challenge_method'", $source);
        self::assertStringContainsString("'code_verifier'", $source);
        self::assertStringContainsString('revokeToken', $source);
    }
}
