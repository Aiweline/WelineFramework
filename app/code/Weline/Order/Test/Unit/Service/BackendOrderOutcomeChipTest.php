<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Order\Service\BackendOrderOutcomeChip;

final class BackendOrderOutcomeChipTest extends TestCase
{
    public function testSucceededBecomesGreenSuccessChipNotRawCode(): void
    {
        $chip = BackendOrderOutcomeChip::present('succeeded');
        self::assertSame('success', $chip['kind']);
        self::assertSame('success', $chip['tone']);
        self::assertSame('check-circle', $chip['icon']);
        self::assertStringContainsString('成功', $chip['label']);
        self::assertStringNotContainsString('succeeded', $chip['label']);
    }

    public function testPresentManyDedupesDuplicateSucceeded(): void
    {
        $chips = BackendOrderOutcomeChip::presentMany('succeeded', 'succeeded', 'failed');
        self::assertCount(2, $chips);
        self::assertSame('success', $chips[0]['kind']);
        self::assertSame('failed', $chips[1]['kind']);
    }
}
