<?php

declare(strict_types=1);

namespace Weline\Widget\Test\Unit\Extends\Module\Weline_Ai\Adapter;

use PHPUnit\Framework\TestCase;
use Weline\Widget\Extends\Module\Weline_Ai\Adapter\WidgetGenerationAdapter;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllWidgetAdapterContractsPass().
 */
final class WidgetAdapterContractsTest extends TestCase
{
    public function testAllWidgetAdapterContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'WidgetGenerationAdapter::testAdapterCodeMatchesWidgetBuilderScenario' => function () {
            $this->assert_WidgetGenerationAdapter_testAdapterCodeMatchesWidgetBuilderScenario();
        },
        ];
    }

private function assert_WidgetGenerationAdapter_testAdapterCodeMatchesWidgetBuilderScenario(): void
{
    $adapter = new WidgetGenerationAdapter();
    self::assertSame('widget_generation', $adapter->getCode());
    self::assertSame('1.0.0', $adapter->getVersion());
    self::assertContains('*', $adapter->getSupportedModelTypes());
    self::assertSame([], $adapter->getDefaultModelBindings());
}
}
