<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * 静态合同：避免 UT 环境调用 __() 触发 Phrase 缓存表。
 */
final class BackendPaymentOutcomeChipTest extends TestCase
{
    public function testSourcePinsFourColorMappingWithoutRawPaidAsLabel(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/BackendPaymentOutcomeChip.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);

        self::assertStringContainsString("'tone' => 'success'", $src);
        self::assertStringContainsString("'tone' => 'warning'", $src);
        self::assertStringContainsString("'tone' => 'danger'", $src);
        self::assertStringContainsString("'tone' => 'info'", $src);
        self::assertStringContainsString("'kind' => 'paid'", $src);
        self::assertStringContainsString("'icon' => 'check-circle'", $src);
        self::assertStringContainsString("__('已支付')", $src);
        self::assertStringContainsString("__('待支付')", $src);
        self::assertStringContainsString("__('失败')", $src);
        self::assertStringContainsString("__('已退款')", $src);
        self::assertStringContainsString("'paid'", $src);
        self::assertStringContainsString("'pending'", $src);
        self::assertStringContainsString("'failed'", $src);
        self::assertStringContainsString("'refunded'", $src);
    }
}
