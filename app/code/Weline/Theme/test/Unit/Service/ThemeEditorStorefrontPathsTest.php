<?php
declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\ThemePageTypeResolver;

final class ThemeEditorStorefrontPathsTest extends TestCase
{
    public function testCustomerLayoutIdentityKeepsItsActualPublicRoute(): void
    {
        $resolver = new ThemePageTypeResolver();
        foreach (['login', 'register', 'forgot-password', 'set-password'] as $action) {
            $layout = 'account/' . $action;
            self::assertSame('customer/' . $layout, $resolver->getFrontendUrlPathForPreview($layout));
            self::assertSame('/customer/' . $layout, $resolver->getPreviewPathByPageType($layout));
            self::assertSame($layout, $resolver->resolveLayoutTypeFromUri('/customer/' . $layout));
        }
    }

    public function testCanvasRouteCatalogUsesSameResolverAsTokenPreview(): void
    {
        $resolver = new ThemePageTypeResolver();
        self::assertSame([
            'homepage' => '/',
            'account/login' => '/customer/account/login',
            'custom/nested' => '/custom/nested',
            'policy' => '/policy',
            '["policy","refund"]' => '/policy/refund',
            '["policy","privacy"]' => '/policy/privacy',
        ], $resolver->getPreviewPathsByLayoutTypes(['homepage', 'account/login', 'custom/nested', 'policy'], [
            'homepage' => [['value' => 'default']],
            'account/login' => [['value' => 'default']],
            'custom/nested' => [['value' => 'default']],
            'policy' => [['value' => 'default'], ['value' => 'refund'], ['value' => 'privacy']],
        ]));
    }

    public function testPolicyOptionUsesSameActualRouteForCanvasAndHistoricalToken(): void
    {
        $resolver = new ThemePageTypeResolver();
        self::assertSame('/policy/refund', $resolver->getPreviewPathByPageType('policy', 'refund'));
        self::assertSame('policy/refund', $resolver->getFrontendUrlPathForPreview('policy', 'refund'));
        self::assertSame('/', $resolver->getPreviewPathByPageType('homepage', 'wide'));
        self::assertSame('/customer/account/login', $resolver->getPreviewPathByPageType('account/login', 'compact'));
    }
}
