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
}
