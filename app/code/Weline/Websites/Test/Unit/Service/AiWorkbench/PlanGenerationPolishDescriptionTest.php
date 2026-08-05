<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service\AiWorkbench;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Websites\Service\AiWorkbench\PlanGenerationService;

final class PlanGenerationPolishDescriptionTest extends TestCase
{
    public function testFakeModePolishesWithoutAiRuntime(): void
    {
        $service = (new ReflectionClass(PlanGenerationService::class))->newInstanceWithoutConstructor();
        $result = $service->polishDescription('做一个品牌下载站', true, 'zh_Hans_CN', 'zh_Hans_CN');

        self::assertTrue($result['success']);
        self::assertSame((string)__('润色完成'), $result['message']);
        self::assertNotSame('', (string)($result['description'] ?? ''));
        self::assertStringContainsString('做一个品牌下载站', (string)$result['description']);
        self::assertStringContainsString('# Role & System Prompt', (string)$result['description']);
        self::assertStringContainsString('# Project Context', (string)$result['description']);
        self::assertStringContainsString('# Architecture & Layout', (string)$result['description']);
        self::assertStringContainsString('# Interactive Requirements', (string)$result['description']);
        self::assertStringContainsString('# Code Quality & Constraints', (string)$result['description']);
        self::assertSame('zh_Hans_CN', (string)($result['content_locale'] ?? ''));
        self::assertSame('zh_Hans_CN', (string)($result['plan_locale'] ?? ''));
        self::assertStringContainsString('网站内容语言（访客默认）**: zh_Hans_CN', (string)$result['description']);
    }

    public function testFakeModeRespectsEnglishPlanLocaleAndContentLocale(): void
    {
        $service = (new ReflectionClass(PlanGenerationService::class))->newInstanceWithoutConstructor();
        $result = $service->polishDescription(
            'India TeenPatti APK download site',
            true,
            'en_US',
            'en_US',
            ['en_US', 'hi_IN']
        );

        self::assertTrue($result['success']);
        self::assertSame('en_US', (string)($result['content_locale'] ?? ''));
        self::assertSame('en_US', (string)($result['plan_locale'] ?? ''));
        self::assertSame(['en_US', 'hi_IN'], $result['language_codes'] ?? []);
        self::assertStringContainsString('Website/content language (visitor-facing default)**: en_US', (string)$result['description']);
        self::assertStringContainsString('Related languages**: hi_IN', (string)$result['description']);
        self::assertStringContainsString('You are a world-class full-stack frontend engineer', (string)$result['description']);
        self::assertStringContainsString('related languages: hi_IN', (string)$result['description']);
    }

    public function testFakeModeKeepsChineseBriefWhenPlanLocaleIsChineseButContentIsEnglish(): void
    {
        $service = (new ReflectionClass(PlanGenerationService::class))->newInstanceWithoutConstructor();
        $result = $service->polishDescription(
            '印度 TeenPatti APK 推广站',
            true,
            'en_US',
            'zh_Hans_CN'
        );

        self::assertTrue($result['success']);
        self::assertSame('en_US', (string)($result['content_locale'] ?? ''));
        self::assertSame('zh_Hans_CN', (string)($result['plan_locale'] ?? ''));
        self::assertStringContainsString('网站内容语言（访客默认）**: en_US', (string)$result['description']);
        self::assertStringContainsString('方案/润色语言（运营侧）**: zh_Hans_CN', (string)$result['description']);
        self::assertStringContainsString('你是一位世界顶级的前端全栈工程师', (string)$result['description']);
    }

    public function testEmptyDescriptionIsRejected(): void
    {
        $service = (new ReflectionClass(PlanGenerationService::class))->newInstanceWithoutConstructor();
        $result = $service->polishDescription('   ', false);

        self::assertFalse($result['success']);
        self::assertSame((string)__('请先输入一句话需求'), $result['message']);
        self::assertArrayNotHasKey('description', $result);
    }
}
