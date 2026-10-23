<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Blog\Model\Post;

/**
 * T1 验收：Post 必须声明 translation_of 列（译文指向源文章）。
 */
final class PostTranslationColumnTest extends TestCase
{
    public function testTranslationOfColumnDeclared(): void
    {
        $this->assertTrue(
            \defined(Post::class . '::schema_fields_TRANSLATION_OF'),
            'Post 缺少 schema_fields_TRANSLATION_OF 常量'
        );
        $this->assertSame('translation_of', Post::schema_fields_TRANSLATION_OF);

        $source = (string)\file_get_contents((string)(new \ReflectionClass(Post::class))->getFileName());
        $needle = "public const schema_fields_TRANSLATION_OF = 'translation_of';";
        $position = \strpos($source, $needle);
        $this->assertNotFalse($position, '未找到 schema_fields_TRANSLATION_OF 的声明行');

        $before = \substr($source, \max(0, $position - 320), 320);
        $this->assertStringContainsString('#[Col(', $before, 'translation_of 缺少 #[Col] 字段声明');
        $this->assertStringContainsString('nullable: false', $before, 'translation_of 必须 nullable: false');
        $this->assertStringContainsString('default: 0', $before, 'translation_of 必须 default: 0');
    }
}
