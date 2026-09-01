<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class BlogContentResolverContractTest extends TestCase
{
    public function testResolverSourceChecksPostBeforeCms(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/BlogContentResolver.php';
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('findPublishedPost', $source);
        self::assertStringContainsString('getPublishedPage', $source);
        self::assertStringContainsString('getPublishedPage', $source);
        self::assertStringContainsString('toBlogArticle', $source);
    }
}
