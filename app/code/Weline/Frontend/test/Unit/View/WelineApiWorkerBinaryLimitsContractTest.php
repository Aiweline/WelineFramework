<?php

declare(strict_types=1);

namespace Weline\Frontend\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Binary\Limits;

final class WelineApiWorkerBinaryLimitsContractTest extends TestCase
{
    public function testWorkerMapKeyLimitMatchesFrameworkBinaryLimits(): void
    {
        $script = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/weline-api-worker.js',
        );

        self::assertMatchesRegularExpression(
            '/const MAX_MAP_KEYS = ' . Limits::MAP_KEYS . ';/',
            $script
        );
        self::assertStringContainsString(
            'Map exceeds ' . Limits::MAP_KEYS . ' key limit.',
            $script
        );
        self::assertMatchesRegularExpression(
            '/const MAX_LIST_ITEMS = ' . Limits::LIST_ITEMS . ';/',
            $script
        );
    }
}
