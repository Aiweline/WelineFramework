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
            'site_name' => "Chang'an Hanfu",
        ]);

        self::assertLessThanOrEqual(65, mb_strlen($title));
        self::assertGreaterThanOrEqual(30, mb_strlen($title));
        self::assertStringContainsString('Jino Occasions', $title);
        self::assertStringContainsString("Chang'an Hanfu", $title);
        self::assertStringNotContainsString('...', $title);
    }

    public function testShortTitlesStillAppendFullSiteName(): void
    {
        $composer = new TitleComposer();
        $title = $composer->compose(null, [
            'page_title' => 'Blog',
            'site_name' => "Chang'an Hanfu",
        ]);

        self::assertSame("Blog | Chang'an Hanfu", $title);
    }
}
