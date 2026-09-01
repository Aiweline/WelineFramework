<?php

declare(strict_types=1);

namespace Weline\DataTable\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class DataTableRowActionEventContractTest extends TestCase
{
    public function testRowEventActionsAcceptActionKeyFromRowActionsJson(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/datatable-manager.js',
        );

        self::assertStringContainsString(
            'action.event || action.action || action.name',
            $source,
        );
    }
}
