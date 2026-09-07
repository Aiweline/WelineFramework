<?php

declare(strict_types=1);

namespace Weline\Frontend\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Public storefront documents must remain cacheable for a first-time guest.
 * Session/CSRF state is hydrated only when a request already carries a
 * session cookie; interactive mutations can establish it on their API call.
 */
final class GuestStorefrontFpcContractTest extends TestCase
{
    public function testFrontendHeaderGatesSessionAndCsrfOnExistingCookie(): void
    {
        $path = dirname(__DIR__, 3) . '/view/blocks/header/base.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('SessionCookieNameResolver::hasRequestCookie()', $source);
        self::assertMatchesRegularExpression(
            '/\$hasSessionCookie\s*=.*?if\s*\(\$hasSessionCookie\).*?SessionFactory::frontend\(\)/s',
            $source,
        );
        self::assertMatchesRegularExpression(
            '/if\s*\(\$hasSessionCookie\).*?Token::create\(\'csrf\'/s',
            $source,
        );
    }

    public function testDeliveryWidgetUsesReadOnlyGuestShellWithoutCookie(): void
    {
        $path = dirname(__DIR__, 4)
            . '/Checkout/view/theme/frontend/widgets/header/checkout-delivery-context/default.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('SessionCookieNameResolver::hasRequestCookie()', $source);
        self::assertMatchesRegularExpression(
            '/if\s*\(\$isEditorMode\).*?elseif\s*\(\$hasSessionCookie\).*?getContext\(\)/s',
            $source,
        );
    }

    public function testQuickAddHookDoesNotStartSessionForAnonymousShell(): void
    {
        $path = dirname(__DIR__, 4)
            . '/Shipping/view/hooks/Weline_Checkout/frontend/widgets/checkout-delivery-context/quick-add.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('SessionCookieNameResolver::hasRequestCookie()', $source);
        self::assertMatchesRegularExpression(
            '/if\s*\(\$hasSessionCookie\).*?createSession\(\).*?sessionCountry/s',
            $source,
        );
        self::assertStringContainsString('csrf="off"', $source);
    }
}
