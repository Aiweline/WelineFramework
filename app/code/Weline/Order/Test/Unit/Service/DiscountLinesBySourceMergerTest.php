<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Order\Service\DiscountLinesBySourceMerger;

final class DiscountLinesBySourceMergerTest extends TestCase
{
    public function testMergeReplacesOnlyTargetSourceTypes(): void
    {
        $existing = [
            ['key' => 'admin_adj:1', 'source_type' => 'admin_adjustment', 'amount_minor' => -1000],
            ['key' => 'pmi:old', 'source_type' => 'payment_method_incentive', 'amount_minor' => -200],
            ['key' => 'other', 'source_type' => 'other', 'amount_minor' => -50],
        ];
        $incoming = [
            ['key' => 'pmi:new', 'source_type' => 'payment_method_incentive', 'amount_minor' => -300],
        ];
        $merged = DiscountLinesBySourceMerger::merge(
            $existing,
            $incoming,
            [DiscountLinesBySourceMerger::SOURCE_PAYMENT_INCENTIVE],
        );
        $sources = array_map(
            static fn (array $row): string => (string)$row['source_type'],
            $merged,
        );
        self::assertContains('admin_adjustment', $sources);
        self::assertContains('other', $sources);
        self::assertContains('payment_method_incentive', $sources);
        self::assertSame(1, substr_count(implode(',', $sources), 'payment_method_incentive'));
        $pmi = null;
        foreach ($merged as $row) {
            if (($row['source_type'] ?? '') === 'payment_method_incentive') {
                $pmi = $row;
            }
        }
        self::assertSame('pmi:new', $pmi['key'] ?? null);
        self::assertSame(-300, (int)($pmi['amount_minor'] ?? 0));
    }

    public function testAbsSumForAdminSource(): void
    {
        $lines = [
            ['source_type' => 'admin_adjustment', 'amount_minor' => -1500],
            ['source_type' => 'admin_adjustment', 'amount_minor' => -500],
            ['source_type' => 'payment_method_incentive', 'amount_minor' => -200],
        ];
        self::assertSame(2000, DiscountLinesBySourceMerger::absSumForSource(
            $lines,
            DiscountLinesBySourceMerger::adminSource(),
        ));
    }
}
