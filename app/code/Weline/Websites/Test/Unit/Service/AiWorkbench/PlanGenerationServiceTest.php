<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service\AiWorkbench;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Api\AiRuntimeInterface;
use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Websites\Service\AiWorkbench\PlanGenerationService;

class PlanGenerationServiceTest extends TestCase
{
    public function testGeneratePlanRejectsInvalidAiPayload(): void
    {
        $runtime = $this->createMock(AiRuntimeInterface::class);
        $runtime->expects(self::once())
            ->method('generateStream')
            ->willReturnCallback(static function (string $prompt, callable $callback): void {
                $callback('not-json-response');
            });

        $service = new PlanGenerationService($this->resolverForAiRuntime($runtime));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('model did not return a valid JSON plan');
        $service->generatePlan([
            'description' => '做一个品牌下载站，突出信任感和下载转化。',
            'reference_urls' => ['https://example.com/ref'],
        ], '做一个品牌下载站，突出信任感和下载转化。');
    }

    public function testAiPlanKeepsExplicitChinesePagesInPageBuilderCodes(): void
    {
        $aiPlan = [
            'site_positioning' => '面向中文用户的高端咖啡器具品牌官网',
            'brand_tone' => '高级、清晰、可信',
            'color_palette' => ['#0f172a', '#2563eb', '#10b981'],
            'visual_style' => '高端产品营销视觉',
            'seo_keywords' => ['咖啡器具', '品牌官网', '高端'],
            'page_types' => ['home_page'],
            'build_mode' => 'pagebuilder_style',
            'shared_elements' => ['header', 'footer'],
            'references_summary' => '依据中文需求整理，无外部参考链接。',
        ];
        $runtime = $this->createMock(AiRuntimeInterface::class);
        $runtime->expects(self::once())
            ->method('generateStream')
            ->willReturnCallback(static function (string $prompt, callable $callback) use ($aiPlan): void {
                $callback((string)\json_encode($aiPlan, JSON_UNESCAPED_UNICODE));
            });

        $service = new PlanGenerationService($this->resolverForAiRuntime($runtime));

        $brief = '创建一个面向中文用户的高端咖啡器具品牌官网，需要首页、产品系列、品牌故事、咖啡学院、联系咨询页面。';
        $plan = $service->generatePlan([
            'description' => $brief,
            'reference_urls' => [],
        ], $brief);

        $this->assertSame('pagebuilder_style', $plan['build_mode']);
        $this->assertContains('home_page', $plan['page_types']);
        $this->assertContains('about_page', $plan['page_types']);
        $this->assertContains('contact_page', $plan['page_types']);
        $this->assertContains('custom_page', $plan['page_types']);
        $this->assertContains('blog_list', $plan['page_types']);
        $this->assertSame('高级、清晰、可信', $plan['brand_tone']);
        $this->assertSame('高端产品营销视觉', $plan['visual_style']);
        $this->assertNotSame('', (string)($plan['plan_markdown'] ?? ''));
        $this->assertStringNotContainsString('No external references', (string)$plan['references_summary']);
        $this->assertStringNotContainsString('High-contrast', (string)$plan['visual_style']);
    }

    /**
     * RuntimeProviderResolver is final, so seed its resolve cache with the
     * stubbed AiRuntimeInterface instead of subclassing the resolver.
     */
    private function resolverForAiRuntime(AiRuntimeInterface $runtime): RuntimeProviderResolver
    {
        $resolver = new RuntimeProviderResolver(new ServiceProviderRegistry());
        $resolved = new \ReflectionProperty(RuntimeProviderResolver::class, 'resolved');
        $resolved->setAccessible(true);
        $resolved->setValue($resolver, [AiRuntimeInterface::class => $runtime]);

        return $resolver;
    }
}
