<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Blog\Service\BlogPostTranslationService;

/**
 * T4 验收：翻译服务骨架（开关 / 语言 / 译文列表 / 缺失语言）契约。
 */
final class BlogPostTranslationServiceTest extends TestCase
{
    public function testSettingConstants(): void
    {
        $this->assertSame('Weline_Blog', BlogPostTranslationService::SETTING_MODULE);
        $this->assertSame('blog', BlogPostTranslationService::SETTING_AREA);
        $this->assertSame('auto_translate_missing_locales', BlogPostTranslationService::SETTING_KEY_AUTO);
    }

    public function testPublicSurface(): void
    {
        $reflection = new \ReflectionClass(BlogPostTranslationService::class);
        $this->assertTrue($reflection->isFinal());

        $methods = [
            'isAutoTranslateEnabled' => 0,
            'setAutoTranslateEnabled' => 1,
            'enabledLocales' => 1,
            'versions' => 1,
            'missingLocales' => 3,
            'translatePost' => 3,
            'autoFill' => 1,
        ];
        foreach ($methods as $method => $parameterCount) {
            $this->assertTrue($reflection->hasMethod($method), '缺少方法 ' . $method);
            $reflected = $reflection->getMethod($method);
            $this->assertTrue($reflected->isPublic(), $method . ' 必须 public');
            $this->assertSame($parameterCount, $reflected->getNumberOfParameters(), $method . ' 参数个数不符');
        }
    }
}
