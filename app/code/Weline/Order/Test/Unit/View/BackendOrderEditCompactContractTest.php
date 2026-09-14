<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class BackendOrderEditCompactContractTest extends TestCase
{
    public function testWritableAndMoreDefaultCollapsed(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/Order/edit.phtml'
        );
        self::assertNotSame('', $src);
        self::assertStringContainsString('data-testid="order-edit-writable-toggle"', $src);
        self::assertStringContainsString('data-testid="order-edit-more-toggle"', $src);
        self::assertStringContainsString('data-testid="order-edit-writable-summary"', $src);
        self::assertStringContainsString('aria-expanded="false"', $src);
        self::assertStringContainsString('--w-stat-tile-min:9rem', $src);
        self::assertStringContainsString('rows="2" data-testid="order-edit-notes"', $src);
        $togglePos = strpos($src, 'data-testid="order-edit-writable-toggle"');
        $selectPos = strpos($src, 'data-testid="order-edit-customer-select"');
        self::assertNotFalse($togglePos);
        self::assertNotFalse($selectPos);
        self::assertLessThan($selectPos, $togglePos);
    }
}
