<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * T6 验收：博客后台表单出现「多语言版本」面板（目标语言、翻译按钮、自动补齐开关），
 * 且静态 JS 注册了对应处理与缓存版本号。
 */
final class BlogAdminTranslationPanelTest extends TestCase
{
    private function blogModulePath(string $suffix): string
    {
        return \dirname(__DIR__, 2) . '/view/' . $suffix;
    }

    public function testTemplateRendersTranslationPanel(): void
    {
        $template = (string)\file_get_contents($this->blogModulePath('templates/backend/post-admin/form.phtml'));
        foreach ([
            '$translation_target_codes',
            '$translation_missing_codes',
            '$translation_versions',
            'data-blog-translate',
            'data-translate-url',
            'data-auto-translate-url',
            'data-auto-translate-enabled',
            'data-blog-translate-button',
            'data-blog-auto-translate',
            'translation_target_codes',
            'translation_missing_codes',
        ] as $needle) {
            $this->assertStringContainsString($needle, $template, '模板缺少：' . $needle);
        }
    }

    public function testScriptRegistersTranslationPanel(): void
    {
        $script = (string)\file_get_contents($this->blogModulePath('statics/js/blog-post-admin-form.js'));
        foreach ([
            'initTranslationPanel',
            'data-blog-translate',
            'auto_translate_url',
            'locales[]',
            'overwrite',
            'as_draft',
        ] as $needle) {
            $this->assertStringContainsString($needle, $script, '脚本缺少：' . $needle);
        }
        $this->assertMatchesRegularExpression('/initTranslationPanel\(\);/', $script, '脚本未在入口调用 initTranslationPanel');
    }

    public function testStaticVersionBumped(): void
    {
        $template = (string)\file_get_contents($this->blogModulePath('templates/backend/post-admin/form.phtml'));
        $this->assertStringContainsString('20261023-translate-v1', $template, '静态资源版本号未更新');
    }
}
