<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Weline\Blog\Controller\Backend\PostAdmin;
use Weline\Blog\Service\BlogPostTranslationService;

/**
 * T5 验收：博客后台控制器暴露翻译端点、注入翻译服务、表单下发翻译数据、保存后自动补齐。
 */
final class BlogPostAdminTranslationEndpointsTest extends TestCase
{
    public function testTranslationEndpoints(): void
    {
        $reflection = new \ReflectionClass(PostAdmin::class);
        foreach (['postTranslate', 'postAutoTranslate'] as $method) {
            $this->assertTrue($reflection->hasMethod($method), '缺少端点 ' . $method);
            $this->assertTrue($reflection->getMethod($method)->isPublic(), $method . ' 必须 public');
        }
    }

    public function testTranslationServiceInjected(): void
    {
        $constructor = (new \ReflectionClass(PostAdmin::class))->getConstructor();
        $this->assertNotNull($constructor);
        $types = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            $types[] = $type instanceof \ReflectionNamedType ? $type->getName() : '';
        }
        $this->assertContains(BlogPostTranslationService::class, $types, '构造函数必须注入 BlogPostTranslationService');
    }

    public function testFormAssignsTranslationDataAndAutoFill(): void
    {
        $source = (string)\file_get_contents((string)(new \ReflectionClass(PostAdmin::class))->getFileName());
        foreach ([
            "assign('translation_versions'",
            "assign('translation_target_codes'",
            "assign('translation_missing_codes'",
            "assign('auto_translate_enabled'",
            "assign('translate_url'",
            "assign('auto_translate_url'",
            "'blog/backend/post-admin/post-translate'",
            "'blog/backend/post-admin/post-auto-translate'",
            '->autoFill(',
        ] as $needle) {
            $this->assertStringContainsString($needle, $source, 'PostAdmin 缺少：' . $needle);
        }
    }
}
