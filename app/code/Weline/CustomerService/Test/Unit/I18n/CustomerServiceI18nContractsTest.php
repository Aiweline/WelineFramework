<?php

declare(strict_types=1);

namespace Weline\CustomerService\Test\Unit\I18n;

use PHPUnit\Framework\TestCase;
use Weline\CustomerService\Service\WidgetTranslationService;

/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllCustomerServiceI18nContractsPass().
 */
final class CustomerServiceI18nContractsTest extends TestCase
{
    public function testAllCustomerServiceI18nContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'HindiIdentityChipCsv::testHindiCsvTranslatesEditEmailIdentity' => function () {
            $this->assert_HindiIdentityChipCsv_testHindiCsvTranslatesEditEmailIdentity();
        },
            'HindiIdentityChipCsv::testBodyEndUsesPathLocaleAndWidgetI18n' => function () {
            $this->assert_HindiIdentityChipCsv_testBodyEndUsesPathLocaleAndWidgetI18n();
        },
            'HindiIdentityChipCsv::testWidgetTranslationServiceIncludesHindi' => function () {
            $this->assert_HindiIdentityChipCsv_testWidgetTranslationServiceIncludesHindi();
        },
        ];
    }

private function assert_HindiIdentityChipCsv_testHindiCsvTranslatesEditEmailIdentity(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/i18n/hi_IN.csv';
    self::assertFileExists($path);
    $hi = '';
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $row = str_getcsv($line, ',', '"', '\\');
        if (($row[0] ?? '') === '修改邮箱身份') {
            $hi = (string) ($row[1] ?? '');
            break;
        }
    }
    self::assertNotSame('', $hi);
    self::assertNotSame('修改邮箱身份', $hi);
    self::assertMatchesRegularExpression('/\p{Devanagari}/u', $hi);
}

private function assert_HindiIdentityChipCsv_testBodyEndUsesPathLocaleAndWidgetI18n(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/view/templates/frontend/widgets/customer-service-float.phtml';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('WidgetI18n::storefrontLocale()', $src);
    self::assertStringNotContainsString('localeFromRequestUri', $src);
    self::assertStringContainsString("\$cs('修改邮箱身份')", $src);
}

private function assert_HindiIdentityChipCsv_testWidgetTranslationServiceIncludesHindi(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/CustomerService' . '/Service/WidgetTranslationService.php');
    self::assertStringContainsString("'hi_IN'", $src);
}

private const FOCUS_KEYS_WidgetSettingsLocaleCsv = ['客服服务', '我的语言', '显示模式', '仅显示译文', '原文+译文', '仅显示原文', 'AI 智能客服'];

/**
 * @return list<array{0: string, 1: string}>
 */
private function localeExpectations_WidgetSettingsLocaleCsv(): array
{
    return [['ar_SA', 'خدمة العملاء'], ['hi_IN', 'ग्राहक सेवा'], ['bn_BD', 'কাস্টমার সার্ভিস'], ['id_ID', 'Layanan pelanggan'], ['ur_PK', 'کسٹمر سروس'], ['en_US', 'Customer Service'], ['fr_FR', 'Service client'], ['es_ES', 'Servicio al cliente'], ['pt_BR', 'Serviço de atendimento']];
}

private function containsCjk_WidgetSettingsLocaleCsv(string $value): bool
{
    return (bool) preg_match('/\p{Han}/u', $value);
}
}
