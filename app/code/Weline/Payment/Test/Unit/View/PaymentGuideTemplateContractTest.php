<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class PaymentGuideTemplateContractTest extends TestCase
{
    public function testBuiltInProviderGuideAndPolicyTemplatesExist(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $providers = [
            'fake_card' => [
                'guide' => $moduleRoot . '/view/templates/Frontend/guide/payment/fake_card/guide.phtml',
                'policy' => $moduleRoot . '/view/templates/Frontend/guide/payment/fake_card/policy.phtml',
                'agreement' => $moduleRoot . '/view/templates/Frontend/guide/payment/fake_card/agreement.phtml',
                'guide_class' => $moduleRoot . '/extends/module/Weline_Payment/PaymentCustomerGuide/FakeCardCustomerGuide.php',
            ],
            'paypal' => [
                'guide' => $moduleRoot . '/view/templates/Frontend/guide/payment/paypal/guide.phtml',
                'policy' => $moduleRoot . '/view/templates/Frontend/guide/payment/paypal/policy.phtml',
                'agreement' => $moduleRoot . '/view/templates/Frontend/guide/payment/paypal/agreement.phtml',
                'guide_class' => $moduleRoot . '/extends/module/Weline_Payment/PaymentCustomerGuide/PayPalCustomerGuide.php',
            ],
            'stripe' => [
                'guide' => $moduleRoot . '/view/templates/Frontend/guide/payment/stripe/guide.phtml',
                'policy' => $moduleRoot . '/view/templates/Frontend/guide/payment/stripe/policy.phtml',
                'agreement' => $moduleRoot . '/view/templates/Frontend/guide/payment/stripe/agreement.phtml',
                'guide_class' => $moduleRoot . '/extends/module/Weline_Payment/PaymentCustomerGuide/StripeCustomerGuide.php',
            ],
        ];

        self::assertFileExists($moduleRoot . '/view/templates/Frontend/guide/payment/index.phtml');

        foreach ($providers as $methodCode => $paths) {
            self::assertFileExists($paths['guide'], 'Missing guide template for ' . $methodCode);
            self::assertFileExists($paths['policy'], 'Missing policy template for ' . $methodCode);
            self::assertFileExists($paths['agreement'], 'Missing agreement template for ' . $methodCode);
            self::assertFileExists($paths['guide_class'], 'Missing guide class for ' . $methodCode);
            self::assertStringContainsString("return '" . $methodCode . "';", (string) file_get_contents($paths['guide_class']));
        }
    }

    public function testHubTemplateUsesGuideEntryLinks(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $hubTemplate = (string) file_get_contents($moduleRoot . '/view/templates/Frontend/guide/payment/index.phtml');

        self::assertStringContainsString('payment_guide_entries', $hubTemplate);
        self::assertStringContainsString('amazon-payment-guide-listing', $hubTemplate);
        self::assertStringContainsString('amazon-shell-open.phtml', $hubTemplate);
        self::assertStringContainsString('<lang>查看支付指南</lang>', $hubTemplate);
        self::assertStringContainsString('<lang>查看支付政策</lang>', $hubTemplate);
        self::assertStringContainsString('<lang>查看用户协议</lang>', $hubTemplate);
    }

    public function testSidebarUsesProviderArticleHierarchy(): void
    {
        $sidebar = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Frontend/guide/payment/partials/amazon-sidebar.phtml',
        );

        self::assertStringContainsString('amazon-payment-guide-layout__nav-group', $sidebar);
        self::assertStringContainsString('amazon-payment-guide-layout__nav-group-label', $sidebar);
        self::assertStringContainsString('amazon-payment-guide-layout__nav-article', $sidebar);
        self::assertStringContainsString('$guideTitle', $sidebar);
        self::assertStringContainsString('$policyTitle', $sidebar);
        self::assertStringContainsString('$agreementTitle', $sidebar);
        self::assertStringNotContainsString('amazon-payment-guide-layout__nav-sub', $sidebar);
    }

    public function testArticleTemplatesUseSharedBreadcrumbPartial(): void
    {
        foreach ([
            'paypal/guide.phtml',
            'paypal/policy.phtml',
            'paypal/agreement.phtml',
            'stripe/guide.phtml',
            'stripe/policy.phtml',
            'stripe/agreement.phtml',
            'fake_card/guide.phtml',
            'fake_card/policy.phtml',
            'fake_card/agreement.phtml',
        ] as $relativePath) {
            $content = (string) file_get_contents(
                dirname(__DIR__, 3) . '/view/templates/Frontend/guide/payment/' . $relativePath,
            );
            self::assertStringContainsString('amazon-breadcrumb.phtml', $content, $relativePath);
        }
    }

    public function testGuideTemplatesUseLangTagsNotPhpTranslate(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $templates = [
            $moduleRoot . '/view/templates/Frontend/guide/payment/index.phtml',
            $moduleRoot . '/view/templates/Frontend/guide/payment/paypal/guide.phtml',
            $moduleRoot . '/view/templates/Frontend/guide/payment/paypal/policy.phtml',
            $moduleRoot . '/view/templates/Frontend/guide/payment/paypal/agreement.phtml',
            $moduleRoot . '/view/templates/Frontend/guide/payment/stripe/guide.phtml',
            $moduleRoot . '/view/templates/Frontend/guide/payment/stripe/policy.phtml',
            $moduleRoot . '/view/templates/Frontend/guide/payment/stripe/agreement.phtml',
            $moduleRoot . '/view/templates/Frontend/guide/payment/fake_card/guide.phtml',
            $moduleRoot . '/view/templates/Frontend/guide/payment/fake_card/policy.phtml',
            $moduleRoot . '/view/templates/Frontend/guide/payment/fake_card/agreement.phtml',
            $moduleRoot . '/view/templates/Frontend/guide/payment/partials/amazon-sidebar.phtml',
        ];

        foreach ($templates as $template) {
            $content = (string) file_get_contents($template);
            self::assertStringContainsString('<lang>', $content, $template);
            self::assertDoesNotMatchRegularExpression('/<\?=\s*__\(/', $content, $template);
        }
    }

    public function testGuideTemplatesUseUrlTagForNavigationLinks(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $sidebar = (string) file_get_contents($moduleRoot . '/view/templates/Frontend/guide/payment/partials/amazon-sidebar.phtml');
        self::assertStringContainsString("@url{'guide/payment'}", $sidebar);
        self::assertStringContainsString('@url{$guideRoute}', $sidebar);
        self::assertStringContainsString('@url{$policyRoute}', $sidebar);
        self::assertStringContainsString('@url{$agreementRoute}', $sidebar);
        self::assertDoesNotMatchRegularExpression('/href="\/guide\/payment/', $sidebar);

        $index = (string) file_get_contents($moduleRoot . '/view/templates/Frontend/guide/payment/index.phtml');
        self::assertStringContainsString('@url{$guideRoute}', $index);
        self::assertStringContainsString('@url{$policyRoute}', $index);
        self::assertStringContainsString('@url{$agreementRoute}', $index);
        self::assertDoesNotMatchRegularExpression('/href="\/guide\/payment/', $index);

        foreach ([
            'paypal/guide.phtml',
            'paypal/policy.phtml',
            'paypal/agreement.phtml',
            'stripe/guide.phtml',
            'stripe/policy.phtml',
            'stripe/agreement.phtml',
            'fake_card/guide.phtml',
            'fake_card/policy.phtml',
            'fake_card/agreement.phtml',
        ] as $relativePath) {
            $content = (string) file_get_contents($moduleRoot . '/view/templates/Frontend/guide/payment/' . $relativePath);
            self::assertStringContainsString('amazon-breadcrumb.phtml', $content, $relativePath);
            self::assertStringNotContainsString('payment_guide_hub_url', $content, $relativePath);
        }

        $breadcrumb = (string) file_get_contents($moduleRoot . '/view/templates/Frontend/guide/payment/partials/amazon-breadcrumb.phtml');
        self::assertStringContainsString("@url{'guide/payment'}", $breadcrumb);
        self::assertStringContainsString('@url{$guideRoute}', $breadcrumb);
        self::assertDoesNotMatchRegularExpression('/href="\/guide\/payment/', $breadcrumb);
    }

    public function testGuidePhraseEnUsTranslationsAreNotChinesePlaceholders(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $enPath = $moduleRoot . '/i18n/en_US.csv';
        self::assertFileExists($enPath);

        $translations = [];
        $fp = fopen($enPath, 'r');
        self::assertNotFalse($fp);
        while (($row = fgetcsv($fp, 0, ',', '"', '\\')) !== false) {
            if (count($row) >= 2) {
                $translations[$row[0]] = $row[1];
            }
        }
        fclose($fp);

        $files = array_unique(array_merge(
            glob($moduleRoot . '/view/templates/Frontend/guide/payment/**/*.phtml') ?: [],
            glob($moduleRoot . '/view/templates/Frontend/guide/payment/*.phtml') ?: [],
            glob($moduleRoot . '/view/templates/Frontend/guide/payment/partials/*.phtml') ?: [],
            glob($moduleRoot . '/extends/module/Weline_Payment/PaymentCustomerGuide/*.php') ?: [],
        ));

        $phrases = [];
        foreach ($files as $file) {
            $content = (string) file_get_contents($file);
            if (preg_match_all('/<lang(?:\s[^>]*)?>(.*?)<\/lang>/su', $content, $m)) {
                foreach ($m[1] as $phrase) {
                    $phrases[$phrase] = true;
                }
            }
            if (preg_match_all('/@lang[\({]["\']?([^"\')\}]+)["\']?[\)}]/u', $content, $m2)) {
                foreach ($m2[1] as $phrase) {
                    $phrases[$phrase] = true;
                }
            }
            if (preg_match_all('/__\([\'"](.+?)[\'"]\)/u', $content, $m3)) {
                foreach ($m3[1] as $phrase) {
                    $phrases[$phrase] = true;
                }
            }
        }

        $bad = [];
        foreach (array_keys($phrases) as $phrase) {
            // Brand / Latin-only sources may keep identical en_US values.
            if (!preg_match('/\p{Han}/u', $phrase)) {
                continue;
            }
            $trans = $translations[$phrase] ?? null;
            if ($trans === null || preg_match('/\p{Han}/u', $trans)) {
                $bad[] = $phrase;
            }
        }

        self::assertSame([], $bad, 'Guide phrases still have Chinese placeholders in en_US.csv');
    }

    public function testAmazonStyleAssetsAndPartialsExist(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $themeRoot = dirname($moduleRoot) . '/Theme';

        self::assertFileExists($themeRoot . '/view/statics/css/widgets/amazon-payment-guide.css');
        self::assertFileExists($moduleRoot . '/view/theme/frontend/layouts/payment_guide/default.phtml');
        self::assertFileExists($moduleRoot . '/view/templates/Frontend/guide/payment/partials/amazon-sidebar.phtml');
        self::assertFileExists($moduleRoot . '/view/templates/Frontend/guide/payment/partials/amazon-shell-open.phtml');

        $layout = (string) file_get_contents($moduleRoot . '/view/theme/frontend/layouts/payment_guide/default.phtml');
        self::assertStringContainsString('amazon-payment-guide.css', $layout);
        self::assertStringContainsString('{{meta.content}}', $layout);
        self::assertStringContainsString('Weline_Theme::frontend::layouts::payment_guide::head-after', $layout);
    }
}
