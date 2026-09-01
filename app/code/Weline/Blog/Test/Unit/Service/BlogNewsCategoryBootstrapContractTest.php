<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Blog\Service\BlogNewsCategoryBootstrap;

final class BlogNewsCategoryBootstrapContractTest extends TestCase
{
    public function testNewsSlugAndNameConstants(): void
    {
        self::assertSame('news', BlogNewsCategoryBootstrap::NEWS_SLUG);
        self::assertSame('新闻中心', BlogNewsCategoryBootstrap::NEWS_NAME);
    }

    public function testUpgradeInvokesNewsCategoryBootstrap(): void
    {
        $upgrade = (string)file_get_contents(dirname(__DIR__, 3) . '/Setup/Upgrade.php');
        self::assertStringContainsString('BlogNewsCategoryBootstrap', $upgrade);
        self::assertStringContainsString('->ensure(0)', $upgrade);
    }
}
