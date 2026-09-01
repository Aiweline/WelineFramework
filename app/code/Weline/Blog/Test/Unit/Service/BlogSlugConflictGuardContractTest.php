<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class BlogSlugConflictGuardContractTest extends TestCase
{
    public function testGuardUsesCmsQueryInsteadOfCmsModel(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/BlogSlugConflictGuard.php';
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('getPageForConflictCheck', $source);
        self::assertStringNotContainsString('Weline\\Cms\\Model\\Page', $source);
        self::assertStringContainsString('博客 slug 与 CMS blog 页面冲突', (string)file_get_contents(dirname(__DIR__, 3) . '/i18n/zh_Hans_CN.csv'));
    }
}
