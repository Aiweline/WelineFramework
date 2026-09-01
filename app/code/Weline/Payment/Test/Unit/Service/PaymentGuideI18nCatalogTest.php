<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Service\TranslationCollector;
use Weline\Payment\Service\PaymentGuideI18nCatalog;

final class PaymentGuideI18nCatalogTest extends TestCase
{
    public function testCollectsPayPalGuidePhrasesFromTemplates(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $collector = new TranslationCollector();
        $phrases = [];

        foreach ($collector->collectLazy($moduleRoot, 'Weline_Payment') as $phrase => $info) {
            $file = str_replace('\\', '/', (string) ($info['file'] ?? ''));
            if (!str_contains($file, 'guide/payment/paypal/')) {
                continue;
            }
            $phrases[$phrase] = $file;
        }

        self::assertArrayHasKey('支付前准备', $phrases);
        self::assertArrayHasKey('支付确认', $phrases);
        self::assertStringContainsString('guide.phtml', $phrases['支付前准备']);
    }

    public function testEnUsCsvContainsEnglishGuideTranslations(): void
    {
        $csvFile = dirname(__DIR__, 3) . '/i18n/en_US.csv';
        self::assertFileExists($csvFile);

        $rows = $this->readCsvPairs($csvFile);
        self::assertSame('Before you pay', $rows['支付前准备'] ?? '');
        self::assertSame('Payment confirmation', $rows['支付确认'] ?? '');
        self::assertSame('Payment Methods Guide', $rows['支付方式指南'] ?? '');
        self::assertSame('Local Test Payment', $rows['本地测试支付'] ?? '');
        self::assertSame('PayPal Payment Guide', $rows['PayPal 支付指南'] ?? '');
    }

    public function testCatalogClassDefinesAuditContract(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/PaymentGuideI18nCatalog.php');
        self::assertStringContainsString('function audit(', $source);
        self::assertStringContainsString('function listPhraseKeys(', $source);
        self::assertStringContainsString("const HUB_MODULE = 'Weline_Payment'", $source);
        self::assertStringContainsString('const SOURCE_LOCALE', $source);
    }

    public function testSourceLocaleRequiresMatchingTranslation(): void
    {
        $catalog = new PaymentGuideI18nCatalog(
            new \Weline\Payment\Service\PaymentCustomerGuideRegistry(),
            new TranslationCollector(),
        );
        $method = new \ReflectionMethod($catalog, 'isTranslated');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($catalog, '支付指南', '支付指南', PaymentGuideI18nCatalog::SOURCE_LOCALE));
        self::assertFalse($method->invoke($catalog, '支付指南', 'Payment guide', PaymentGuideI18nCatalog::SOURCE_LOCALE));
        self::assertTrue($method->invoke($catalog, '支付指南', 'Payment guide', 'en_US'));
        self::assertFalse($method->invoke($catalog, '支付指南', '支付指南', 'en_US'));
    }

    /**
     * @return array<string, string>
     */
    private function readCsvPairs(string $csvFile): array
    {
        $rows = [];
        $content = (string) file_get_contents($csvFile);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        foreach (explode("\n", $content) as $line) {
            if ($line === '' || !str_contains($line, ',')) {
                continue;
            }
            if ($line[0] === '"') {
                if (!preg_match('/^"((?:[^"]|"")*)","((?:[^"]|"")*)"$/', $line, $matches)) {
                    continue;
                }
                $rows[str_replace('""', '"', $matches[1])] = str_replace('""', '"', $matches[2]);
                continue;
            }
            [$source, $translation] = explode(',', $line, 2);
            $rows[$source] = trim($translation, '"');
        }

        return $rows;
    }
}