<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/** 费用模板后台：可编辑、展示金额/基础货币、禁止自由货币输入。 */
final class RateTemplateAdminUiContractTest extends TestCase
{
    private function template(): string
    {
        return (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/RateTemplate/index.phtml'
        );
    }

    public function testListShowsFeesCurrencyAndEditAction(): void
    {
        $tpl = $this->template();
        self::assertStringContainsString('data-testid="shipping-rate-template-list"', $tpl);
        self::assertStringContainsString('base_fee', $tpl);
        self::assertStringContainsString('currency_code', $tpl);
        self::assertStringContainsString('data-testid="shipping-rate-template-edit"', $tpl);
        self::assertStringContainsString('data-testid="shipping-rate-template-delete"', $tpl);
        self::assertStringContainsString('data-testid="shipping-rate-template-delete-forbidden"', $tpl);
        self::assertStringContainsString('系统种子不可删除', $tpl);
        self::assertStringContainsString('基础费用', $tpl);
        self::assertStringContainsString('货币', $tpl);
    }

    public function testFormLocksBaseCurrencyAndSupportsUpdate(): void
    {
        $tpl = $this->template();
        self::assertStringContainsString('base_currency', $tpl);
        self::assertStringContainsString('template_id', $tpl);
        self::assertStringNotContainsString('name="currency_code"', $tpl);
        self::assertStringContainsString('基础货币', $tpl);
        self::assertMatchesRegularExpression('/保存|更新/', $tpl);
    }

    public function testDesignNoteExplainsBaseCurrencyConversion(): void
    {
        $tpl = $this->template();
        self::assertStringContainsString('data-testid="shipping-rate-template-design-note"', $tpl);
        self::assertStringContainsString('基础货币', $tpl);
        self::assertStringContainsString('汇率', $tpl);
        self::assertStringContainsString('勿在模板里配地区矩阵', $tpl);
    }
}
