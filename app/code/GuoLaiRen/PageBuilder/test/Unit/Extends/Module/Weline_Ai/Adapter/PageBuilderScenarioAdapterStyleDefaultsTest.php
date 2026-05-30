<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Extends\Module\Weline_Ai\Adapter;

use GuoLaiRen\PageBuilder\Extends\Module\Weline_Ai\Adapter\AiSiteAssetsAdapter;
use GuoLaiRen\PageBuilder\Extends\Module\Weline_Ai\Adapter\ComponentGenerationAdapter;
use GuoLaiRen\PageBuilder\Extends\Module\Weline_Ai\Adapter\PlanGenerationAdapter;
use GuoLaiRen\PageBuilder\Extends\Module\Weline_Ai\Style\PageBuilderStyleProvider;
use PHPUnit\Framework\TestCase;

final class PageBuilderScenarioAdapterStyleDefaultsTest extends TestCase
{
    public function testPageBuilderAdaptersDefaultToCardGameBuiltinStyle(): void
    {
        $expected = [PageBuilderStyleProvider::CARD_GAME_STYLE_CODE];

        self::assertSame($expected, (new PlanGenerationAdapter())->getDefaultStyleCodes());
        self::assertSame($expected, (new ComponentGenerationAdapter())->getDefaultStyleCodes());
        self::assertSame($expected, (new AiSiteAssetsAdapter())->getDefaultStyleCodes());
    }
}
