<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * 默认站 website_id=0 必须可筛选；禁止 >0 才 where。
 */
final class BlogPostAdminDefaultSiteFilterContractTest extends TestCase
{
    public function test_listing_filters_include_default_website_zero(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/BlogPostAdminService.php');
        self::assertStringContainsString('?int $websiteId', $src);
        self::assertStringContainsString('$websiteId !== null && $websiteId >= 0', $src);
        self::assertStringContainsString('禁止 website_id>0 才筛', $src);
        self::assertStringNotContainsString('if ($websiteId > 0)', $src);
    }

    public function test_list_presenter_does_not_call_zero_global(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/BlogPostAdminListPresenter.php');
        self::assertStringNotContainsString("websiteId > 0 ? '#' . \$websiteId : '全站'", $src);
        self::assertStringContainsString("'#' . max(0, \$websiteId)", $src);
    }
}
