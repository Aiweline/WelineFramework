<?php

declare(strict_types=1);

namespace Weline\Widget\Test\Unit\Extends\Module\Weline_Ai\Adapter;

use PHPUnit\Framework\TestCase;
use Weline\Widget\Extends\Module\Weline_Ai\Adapter\WidgetGenerationAdapter;

final class WidgetGenerationAdapterContractTest extends TestCase
{
    public function testAdapterCodeMatchesWidgetBuilderScenario(): void
    {
        $adapter = new WidgetGenerationAdapter();

        self::assertSame('widget_generation', $adapter->getCode());
        self::assertSame('1.0.0', $adapter->getVersion());
        self::assertContains('*', $adapter->getSupportedModelTypes());
        self::assertSame([], $adapter->getDefaultModelBindings());
    }
}
