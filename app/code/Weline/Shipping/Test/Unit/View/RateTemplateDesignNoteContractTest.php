<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/** 费用模板页须说明：计费公式与到达地区解耦，金额按基础货币。 */
final class RateTemplateDesignNoteContractTest extends TestCase
{
    public function testIndexExplainsNoDestinationRateMatrix(): void
    {
        $tpl = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/RateTemplate/index.phtml'
        );
        self::assertStringContainsString('data-testid="shipping-rate-template-design-note"', $tpl);
        self::assertStringContainsString('w-alert__content', $tpl);
        self::assertStringContainsString('勿在模板里配地区矩阵', $tpl);
        self::assertStringContainsString('基础货币', $tpl);
        self::assertStringContainsString('配送服务 / 航线', $tpl);
    }
}
