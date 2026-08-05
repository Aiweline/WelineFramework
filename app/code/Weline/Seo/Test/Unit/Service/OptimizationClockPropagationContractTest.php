<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class OptimizationClockPropagationContractTest extends TestCase
{
    public function testActivityAndControlCenterUseTheOptimizationClock(): void
    {
        $root = \dirname(__DIR__, 3);
        $activity = (string)\file_get_contents($root . '/Service/SeoOptimizationActivityService.php');
        $controlCenter = (string)\file_get_contents($root . '/Service/SeoOptimizationControlCenterService.php');

        self::assertStringContainsString('?OptimizationTiming $timing = null', $activity);
        self::assertStringContainsString('$occurredAt = $this->timing->now();', $activity);
        self::assertStringNotContainsString("\\gmdate('Y-m-d H:i:s'", $activity);
        self::assertStringNotContainsString('\\time()', $activity);

        self::assertStringContainsString('?OptimizationTiming $timing = null', $controlCenter);
        self::assertStringContainsString("'server_time' => \$this->iso(\$this->nowSql())", $controlCenter);
        self::assertStringContainsString('$this->timing->isFuture($next)', $controlCenter);
        self::assertStringNotContainsString("\\gmdate('Y-m-d H:i:s')", $controlCenter);
        self::assertStringNotContainsString('\\time()', $controlCenter);
    }
}
