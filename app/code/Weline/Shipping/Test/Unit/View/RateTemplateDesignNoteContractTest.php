<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/** 费用模板页须说明：计费公式与到达地区解耦。 */
final class RateTemplateDesignNoteContractTest extends TestCase
{
    public function testIndexExplainsNoDestinationRateMatrix(): void
    {
        $tpl = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/RateTemplate/index.phtml'
        );
        self::assertStringContainsString('data-testid="shipping-rate-template-design-note"', $tpl);
        self::assertStringContainsString('w-alert__content', $tpl);
        self::assertStringContainsString('不按到达国家/州省设费率', $tpl);
        self::assertStringContainsString('承运商「支持范围」', $tpl);
    }
}
