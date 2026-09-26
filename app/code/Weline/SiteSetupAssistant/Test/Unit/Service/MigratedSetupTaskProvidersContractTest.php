<?php

declare(strict_types=1);

namespace Weline\SiteSetupAssistant\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * 原硬编码建站 tips 已全部迁到各模块 SetupTask Provider。
 */
final class MigratedSetupTaskProvidersContractTest extends TestCase
{
    public function testFormerHardcodedTipsHaveModuleProviders(): void
    {
        $weline = dirname(__DIR__, 4);
        $expected = [
            'Captcha' => 'CaptchaSetupTaskProvider',
            'Customer' => 'CustomerSocialLoginSetupTaskProvider',
            'Social' => 'SocialPlatformSetupTaskProvider',
            'Payment' => 'PaymentPaypalSetupTaskProvider',
            'Seo' => 'SeoWebsiteAccountSetupTaskProvider',
            'Shipping' => 'ShippingSetupTaskProvider',
            'Currency' => 'CurrencySetupTaskProvider',
            'Visitor' => 'VisitorPixelSetupTaskProvider',
            'CustomerService' => 'CustomerServiceSetupTaskProvider',
            'Cdn' => 'CdnCloudflareSetupTaskProvider',
            'Websites' => 'WebsitesDomainHttpsSetupTaskProvider',
            'Smtp' => 'SmtpSetupTaskProvider',
            'Mail' => 'MailSetupTaskProvider',
        ];

        foreach ($expected as $module => $class) {
            $path = $weline . '/' . $module . '/extends/module/Weline_SiteSetupAssistant/SetupTask/' . $class . '.php';
            self::assertFileExists($path, $module . ' provider missing');
            $src = (string)file_get_contents($path);
            self::assertStringContainsString('extends AbstractSetupTaskProvider', $src);
            $extends = (string)file_get_contents($weline . '/' . $module . '/extends.php');
            self::assertStringContainsString('SetupTaskProviderInterface::class', $extends);
            self::assertStringContainsString($class . '::class', $extends);
        }

        $float = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/backend/widgets/site-setup-assistant-float.phtml'
        );
        self::assertStringContainsString('SetupTaskCollector', $float);
        self::assertStringNotContainsString("'code' => 'captcha'", $float);
        self::assertStringNotContainsString("'code' => 'paypal'", $float);
        self::assertStringNotContainsString("'code' => 'domain_https'", $float);
    }
}
