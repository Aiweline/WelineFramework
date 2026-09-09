<?php

declare(strict_types=1);

namespace Weline\Frontend\Test\Unit\Service\Head;

use PHPUnit\Framework\TestCase;
use Weline\Frontend\Service\Head\TitleComposer;

final class TitleComposerFitContractTest extends TestCase
{
    public function testComposedBlogTitleFitsSoftSerpBudgetWithShortBrand(): void
    {
        $composer = new TitleComposer();
        $title = $composer->compose(null, [
            'page_title' => 'Jino Occasions & Craft: Festival vs Daily Dress',
            'site_name' => 'Yunshang Hanfu · Hanfu Atelier',
        ]);

        self::assertLessThanOrEqual(65, mb_strlen($title));
        self::assertGreaterThanOrEqual(30, mb_strlen($title));
        self::assertStringContainsString('Jino Occasions', $title);
        self::assertStringContainsString('Yunshang Hanfu', $title);
        self::assertStringNotContainsString('...', $title);
    }

    public function testShortTitlesStillAppendFullSiteName(): void
    {
        $composer = new TitleComposer();
        $title = $composer->compose(null, [
            'page_title' => 'Blog',
            'site_name' => 'Yunshang Hanfu · Hanfu Atelier',
        ]);

        self::assertSame('Blog | Yunshang Hanfu · Hanfu Atelier', $title);
    }
}
