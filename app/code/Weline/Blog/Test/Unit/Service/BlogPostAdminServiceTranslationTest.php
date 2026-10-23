<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Blog\Service\BlogPostAdminService;

/**
 * T2 验收：BlogPostAdminService::save() 必须接受 translation_of 并写入模型。
 * save() 依赖数据库模型，这里做源码级契约断言（行为由 T7 真机验收覆盖）。
 */
final class BlogPostAdminServiceTranslationTest extends TestCase
{
    public function testSaveAcceptsTranslationOf(): void
    {
        $file = (string)(new \ReflectionClass(BlogPostAdminService::class))->getFileName();
        $source = (string)\file_get_contents($file);
        $normalized = (string)\preg_replace('/\s+/', ' ', $source);

        $expected = "if (array_key_exists('translation_of', \$data)) { "
            . "\$model->setData(Post::schema_fields_TRANSLATION_OF, max(0, (int)\$data['translation_of'])); }";

        $this->assertStringContainsString(
            $expected,
            $normalized,
            'save() 缺少 translation_of 写入分支（顺序与写法需与 brief 一致）'
        );
    }
}
