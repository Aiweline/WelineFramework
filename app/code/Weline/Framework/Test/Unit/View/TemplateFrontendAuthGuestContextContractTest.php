<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Public storefront template caches must not shard by frontend login Session.
 */
final class TemplateFrontendAuthGuestContextContractTest extends TestCase
{
    public function testFrontendAuthStaticHookCacheContextIsGuestOnlySource(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/View/Template.php'
        );
        self::assertStringContainsString(
            "Storefront public HTML is always guest-safe",
            $source
        );
        self::assertStringContainsString(
            "\$context = 'frontend-auth:0';",
            $source
        );
        self::assertStringNotContainsString(
            'createFrontendSession()',
            $this->extractFrontendAuthMethod($source)
        );
        self::assertStringNotContainsString(
            'isLoggedIn()',
            $this->extractFrontendAuthMethod($source)
        );
    }

    private function extractFrontendAuthMethod(string $source): string
    {
        if (!preg_match(
            '/function frontendAuthStaticHookCacheContext\(\): \?string\s*\{(.*?)(?=\n    private function |\n    public function )/s',
            $source,
            $m
        )) {
            self::fail('frontendAuthStaticHookCacheContext not found');
        }

        return (string) $m[1];
    }
}
