<?php

declare(strict_types=1);

namespace Weline\HelpPay\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class HelpPayFaqPagesContractTest extends TestCase
{
    public function testFaqProvidersAndTemplatesExist(): void
    {
        $base = dirname(__DIR__, 3);
        $slugs = [
            'help-pay-rules',
            'help-pay-privacy',
            'help-pay-payer',
            'selection-share',
        ];
        foreach ($slugs as $slug) {
            $tpl = $base . '/view/templates/frontend/faq/' . $slug . '.phtml';
            self::assertFileExists($tpl, $slug);
            $html = (string) file_get_contents($tpl);
            self::assertStringContainsString('data-testid="faq-' . $slug . '"', $html);
            // SPI body must not repeat Faq view.phtml article <h1> (provider title).
            self::assertDoesNotMatchRegularExpression(
                '/<h[12]\b/i',
                $html,
                $slug . ' SPI must not emit h1/h2; article title lives in Faq view.phtml'
            );
        }
        $providerDir = $base . '/extends/module/Weline_Faq/FaqPageProvider';
        self::assertDirectoryExists($providerDir);
        $files = glob($providerDir . '/*.php') ?: [];
        self::assertGreaterThanOrEqual(4, count($files));
    }
}
