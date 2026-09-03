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

    public function testScopeResolverUsesCurrentRequestLanguageState(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/BlogScopeResolver.php',
        );

        self::assertStringContainsString('State::getLang()', $source);
        self::assertStringContainsString("str_replace('-', '_', \$locale)", $source);
        self::assertStringNotContainsString('$scope->localeCode', $source);
        self::assertStringNotContainsString("w_env('lang'", $source);
    }

    public function testEnglishStorageSuffixStaysBehindPublicSlugBoundary(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/BlogContentResolver.php',
        );

        self::assertStringContainsString('storageSlugForLocale', $source);
        self::assertStringContainsString('public function publicSlugForLocale', $source);
        self::assertStringContainsString("return \$slug . '-en';", $source);
        self::assertStringContainsString("substr(\$slug, 0, -3)", $source);
        self::assertStringContainsString("'storage_slug' => \$storageSlug", $source);
        self::assertStringContainsString('if (!is_array($row)', $source);
        self::assertStringContainsString('$row === []', $source);
        self::assertStringContainsString('(int)($row[Post::schema_fields_ID] ?? 0) <= 0', $source);

        $indexBuilder = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/BlogSearchIndexDocumentBuilder.php',
        );
        self::assertStringContainsString('$this->resolver->publicSlugForLocale', $indexBuilder);
        self::assertStringContainsString("'storage_slug' => \$storageSlug", $indexBuilder);
    }
}
