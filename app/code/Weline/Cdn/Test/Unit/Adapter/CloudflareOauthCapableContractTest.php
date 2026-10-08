<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Adapter;

use PHPUnit\Framework\TestCase;

/**
 * Cloudflare adapter owns one-click OAuth via OauthCapableProviderInterface.
 */
final class CloudflareOauthCapableContractTest extends TestCase
{
    public function testCloudflareImplementsOauthCapableProviderInterface(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Adapter/Cloudflare.php'
        );

        self::assertStringContainsString('use Weline\\Cdn\\Api\\OauthCapableProviderInterface;', $source);
        self::assertStringContainsString(
            'implements AdapterInterface, OauthCapableProviderInterface, OriginNoStoreEdgeHeaderWriterInterface',
            $source,
        );
        self::assertStringContainsString('function supportsOneClickOauth', $source);
        self::assertStringContainsString('function startOauthAuthorization', $source);
        self::assertStringContainsString('function completeOauthAuthorization', $source);
        self::assertStringContainsString('function consumeOauthFailureState', $source);
        self::assertStringContainsString('authorizationUrl(', $source);
        self::assertStringContainsString('withoutStorefrontLocalizationPrefix', $source);
    }

    public function testAdapterResolverExposesOauthCapableHelpers(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/AdapterResolver.php'
        );

        self::assertStringContainsString('function getOauthCapableAdapters', $source);
        self::assertStringContainsString('function getOauthCapableAdapter', $source);
        self::assertStringContainsString('OauthCapableProviderInterface', $source);
    }
}
