<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Blog\Model\Post;
use Weline\Blog\Service\BlogPostAdminListPresenter;

final class BlogPostAdminListPresenterTest extends TestCase
{
    public function testPresentRowMergesColumnsAndScopeMarkers(): void
    {
        $presenter = new BlogPostAdminListPresenter();
        $row = $presenter->presentRow(
            [
                Post::schema_fields_ID => 7,
                Post::schema_fields_WEBSITE_ID => 1,
                Post::schema_fields_TITLE => 'Hello Weline Blog',
                Post::schema_fields_SLUG => 'hello-weline-blog',
                Post::schema_fields_LOCALE => 'zh_Hans_CN',
                Post::schema_fields_CATEGORY_ID => 3,
                Post::schema_fields_STATUS => Post::STATUS_PUBLISHED,
                Post::schema_fields_AUTHOR => 'Weline Team',
                Post::schema_fields_COVER_IMAGE => 'https://cdn.example.com/cover.jpg',
                Post::schema_fields_UPDATED_AT => '2026-08-27 01:41:17',
            ],
            [3 => '技术分享'],
            [1 => 'p05113ef3'],
            [Post::STATUS_PUBLISHED => '已发布'],
        );

        self::assertSame('Hello Weline Blog · hello-weline-blog', $row['article_head']);
        self::assertSame('Weline Team', $row['author']);
        self::assertSame('https://cdn.example.com/cover.jpg', $row['cover_image']);
        self::assertSame('zh_Hans_CN · 技术分享 · 已发布', $row['meta_cluster']);
        self::assertStringContainsString('p05113ef3', $row['scope_markers']);
        self::assertStringContainsString('全店铺', $row['scope_markers']);
        self::assertStringContainsString('全渠道', $row['scope_markers']);
    }
}
