<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service\AiWorkbench;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Service\AiService;
use Weline\Websites\Service\AiWorkbench\PlanGenerationService;

class PlanGenerationServiceTest extends TestCase
{
    public function testGeneratePlanFallsBackWhenAiReturnsInvalidPayload(): void
    {
        $service = new PlanGenerationService(new class() extends AiService {
            public function __construct()
            {
            }

            public function generateStream(
                string $prompt,
                callable $callback,
                ?string $modelCode = null,
                ?string $scenarioCode = null,
                ?string $locale = null,
                array $params = []
            ): void {
                $callback('not-json-response');
            }
        });

        $plan = $service->generatePlan([
            'description' => '做一个品牌下载站，突出信任感和下载转化。',
            'reference_urls' => ['https://example.com/ref'],
        ], '做一个品牌下载站，突出信任感和下载转化。');

        $this->assertSame('pagebuilder_style', $plan['build_mode']);
        $this->assertContains('home_page', $plan['page_types']);
        $this->assertContains('about_page', $plan['page_types']);
        $this->assertContains('contact_page', $plan['page_types']);
        $this->assertNotSame('', (string)($plan['plan_markdown'] ?? ''));
        $this->assertNotSame('', (string)($plan['references_summary'] ?? ''));
    }

    public function testFallbackPlanKeepsExplicitChinesePagesInPageBuilderCodes(): void
    {
        $service = new PlanGenerationService(new class() extends AiService {
            public function __construct()
            {
            }

            public function generateStream(
                string $prompt,
                callable $callback,
                ?string $modelCode = null,
                ?string $scenarioCode = null,
                ?string $locale = null,
                array $params = []
            ): void {
                $callback('not-json-response');
            }
        });

        $brief = self::u('\u521b\u5efa\u4e00\u4e2a\u9762\u5411\u4e2d\u6587\u7528\u6237\u7684\u9ad8\u7aef\u5496\u5561\u5668\u5177\u54c1\u724c\u5b98\u7f51\uff0c\u9700\u8981\u9996\u9875\u3001\u4ea7\u54c1\u7cfb\u5217\u3001\u54c1\u724c\u6545\u4e8b\u3001\u5496\u5561\u5b66\u9662\u3001\u8054\u7cfb\u54a8\u8be2\u9875\u9762\u3002');
        $plan = $service->generatePlan([
            'description' => $brief,
            'reference_urls' => [],
        ], $brief);

        $this->assertContains('home_page', $plan['page_types']);
        $this->assertContains('about_page', $plan['page_types']);
        $this->assertContains('contact_page', $plan['page_types']);
        $this->assertContains('custom_page', $plan['page_types']);
        $this->assertContains('blog_list', $plan['page_types']);
        $this->assertSame(self::u('\u9ad8\u7ea7\u3001\u6e05\u6670\u3001\u53ef\u4fe1'), $plan['brand_tone']);
        $this->assertSame(self::u('\u9ad8\u7aef\u4ea7\u54c1\u8425\u9500\u89c6\u89c9'), $plan['visual_style']);
        $this->assertStringNotContainsString('No external references', (string)$plan['references_summary']);
        $this->assertStringNotContainsString('High-contrast', (string)$plan['visual_style']);
    }

    private static function u(string $jsonEscaped): string
    {
        $decoded = \json_decode('"' . $jsonEscaped . '"');

        return \is_string($decoded) ? $decoded : $jsonEscaped;
    }
}
