<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class FooterSocialLoginLinkWidgetContractTest extends TestCase
{
    public function testFooterSocialLoginLinkRegistersDefaultInjection(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Customer/widget.php';
        $tpl = 'Weline_Customer::templates/frontend/widgets/footer-social-login-link.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/frontend/widgets/footer-social-login-link.phtml');
        self::assertStringContainsString('@widget.code {footer-social-login-link}', $src);
        self::assertStringContainsString('@widget.slot {footer-payment-account-links}', $src);
        self::assertStringContainsString('"slot":"footer-payment-account-links"', $src);
        self::assertStringContainsString('"required":true', $src);
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
