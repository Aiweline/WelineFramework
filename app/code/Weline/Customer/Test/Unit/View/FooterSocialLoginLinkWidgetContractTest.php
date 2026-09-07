<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class FooterSocialLoginLinkWidgetContractTest extends TestCase
{
    public function testFooterSocialLoginLinkRegistersDefaultInjection(): void
    {
        $widgetFile = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Customer/widget.php';
        self::assertFileExists($widgetFile);
        $widgets = require $widgetFile;
        self::assertArrayHasKey('footer-social-login-link', $widgets);
        $widget = $widgets['footer-social-login-link'];
        self::assertSame('footer-payment-account-links', $widget['slot'] ?? null);
        self::assertSame(
            'Weline_Customer::templates/frontend/widgets/footer-social-login-link.phtml',
            $widget['template'] ?? null
        );
        $injection = $widget['default_injections'][0] ?? [];
        self::assertSame('homepage', $injection['layout_type'] ?? null);
        self::assertSame('footer-payment-account-links', $injection['slot'] ?? null);
        self::assertSame('footer', $injection['area'] ?? null);
        self::assertSame(5, (int)($injection['sort_order'] ?? -1));
        self::assertSame('社媒登录', $injection['config']['label'] ?? null);
    }

    public function testFooterSocialLoginLinkTemplatePointsToGuide(): void
    {
        $template = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/footer-social-login-link.phtml'
        );
        self::assertStringContainsString('data-testid="footer-social-login-link"', $template);
        self::assertStringContainsString("@url{'guide/social-login'}", $template);
        self::assertStringContainsString('footer-payment-account-links', $template);
        self::assertStringContainsString('社媒登录', $template);
    }

    public function testThemeEnUsCsvTranslatesFooterSocialLoginLabel(): void
    {
        $csv = dirname(__DIR__, 4) . '/Theme/i18n/en_US.csv';
        self::assertFileExists($csv);
        $catalog = [];
        $handle = fopen($csv, 'rb');
        self::assertIsResource($handle);
        while (($row = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
            $source = preg_replace('/^\xEF\xBB\xBF/', '', (string)($row[0] ?? ''));
            if ($source === '') {
                continue;
            }
            $catalog[$source] = (string)($row[1] ?? '');
        }
        fclose($handle);
        self::assertSame('Social Login', $catalog['社媒登录'] ?? null);
    }
}
