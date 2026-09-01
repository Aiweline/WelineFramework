<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

require_once dirname(__DIR__) . '/bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Payment\Service\PaymentGuideTemplateScanner;

final class PaymentGuideTemplateScannerTest extends TestCase
{
    public function testBuiltInPayPalGuidePassesLangContract(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $scanner = new PaymentGuideTemplateScanner(new \Weline\Payment\Service\PaymentCustomerGuideRegistry());
        $guidePath = $moduleRoot . '/view/templates/Frontend/guide/payment/paypal/guide.phtml';
        $policyPath = $moduleRoot . '/view/templates/Frontend/guide/payment/paypal/policy.phtml';

        foreach ([$guidePath, $policyPath] as $path) {
            $content = (string) file_get_contents($path);
            $violations = $scanner->scanTemplateContent(
                'paypal',
                'Weline_Payment',
                'Weline_Payment::templates/Frontend/guide/payment/paypal/guide.phtml',
                $path,
                $content,
            );
            self::assertSame([], $violations, $path);
        }
    }

    public function testDetectsMissingLangTags(): void
    {
        $scanner = new PaymentGuideTemplateScanner(new \Weline\Payment\Service\PaymentCustomerGuideRegistry());
        $violations = $scanner->scanTemplateContent(
            'demo',
            'Weline_Payment',
            'Weline_Payment::templates/Frontend/guide/payment/demo/guide.phtml',
            'demo/guide.phtml',
            '<h1>PayPal Payment Guide</h1><p>Please pay with PayPal.</p>',
        );

        $types = array_column($violations, 'type');
        self::assertContains(PaymentGuideTemplateScanner::VIOLATION_NO_LANG_TAGS, $types);
        self::assertContains(PaymentGuideTemplateScanner::VIOLATION_UNWRAPPED_VISIBLE_TEXT, $types);
    }

    public function testDetectsForbiddenPhpTranslate(): void
    {
        $scanner = new PaymentGuideTemplateScanner(new \Weline\Payment\Service\PaymentCustomerGuideRegistry());
        $violations = $scanner->scanTemplateContent(
            'demo',
            'Weline_Payment',
            'demo/guide.phtml',
            'demo/guide.phtml',
            '<h1><?= __(\'支付指南\') ?></h1>',
        );

        self::assertContains(
            PaymentGuideTemplateScanner::VIOLATION_PHP_TRANSLATE_FORBIDDEN,
            array_column($violations, 'type'),
        );
    }

    public function testResolvesGuideTemplatePath(): void
    {
        if (!\defined('APP_CODE_PATH')) {
            self::markTestSkipped('APP_CODE_PATH is not defined in this PHPUnit bootstrap.');
        }

        $scanner = new PaymentGuideTemplateScanner(new \Weline\Payment\Service\PaymentCustomerGuideRegistry());
        $path = $scanner->resolveTemplatePath('Weline_Payment::templates/Frontend/guide/payment/paypal/guide.phtml');

        self::assertStringEndsWith('guide/payment/paypal/guide.phtml', str_replace('\\', '/', $path));
        self::assertFileExists($path);
    }
}
