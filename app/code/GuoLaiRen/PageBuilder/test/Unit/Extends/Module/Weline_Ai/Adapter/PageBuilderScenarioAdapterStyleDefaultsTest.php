<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Extends\Module\Weline_Ai\Adapter;

use GuoLaiRen\PageBuilder\Extends\Module\Weline_Ai\Adapter\AiSiteAssetsAdapter;
use GuoLaiRen\PageBuilder\Extends\Module\Weline_Ai\Adapter\ComponentGenerationAdapter;
use GuoLaiRen\PageBuilder\Extends\Module\Weline_Ai\Adapter\PlanGenerationAdapter;
use PHPUnit\Framework\TestCase;

final class PageBuilderScenarioAdapterStyleDefaultsTest extends TestCase
{
    public function testPageBuilderAdaptersDoNotForceVerticalBuiltinStyleByDefault(): void
    {
        self::assertSame([], (new PlanGenerationAdapter())->getDefaultStyleCodes());
        self::assertSame([], (new ComponentGenerationAdapter())->getDefaultStyleCodes());
        self::assertSame([], (new AiSiteAssetsAdapter())->getDefaultStyleCodes());
    }
}
