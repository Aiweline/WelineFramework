<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Service\OptimizationRunSelection;

final class OptimizationRunSelectionTest extends TestCase
{
    public function testOmittedRunSelectsLatestCycleRunAndExplicitRunWins(): void
    {
        $selection = new OptimizationRunSelection();

        self::assertSame([42 => 42], $selection->select([7 => 7, 42 => 42, 19 => 19]));
        self::assertSame([7 => 7], $selection->select([7 => 7, 42 => 42], 7));
        self::assertSame([], $selection->select([]));
    }
}
