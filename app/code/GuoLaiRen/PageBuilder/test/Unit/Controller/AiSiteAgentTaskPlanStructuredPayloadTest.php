<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Controller;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use GuoLaiRen\PageBuilder\Model\Page;
use PHPUnit\Framework\TestCase;

final class AiSiteAgentTaskPlanStructuredPayloadTest extends TestCase
{
    public function testStructuredTaskPlanPayloadMirrorsIntoVirtualThemeDraftAndSummary(): void
    {
        $structured = [
            'signature' => 'manual-field-edit-unit',
            'shared_tasks' => [
                ['task_key' => 'shared:header'],
            ],
            'page_tasks' => [
                Page::TYPE_HOME => [
                    [
                        'task_key' => 'page:home:hero',
                        'task_script' => [
                            'field_content_requirements' => [
                                [
                                    'field' => 'hero_title',
                                    'sample' => 'Edited hero H1',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->normalizePayload([
            'virtual_theme_plan' => ['draft_markdown' => 'manual edit'],
            'task_plan_structured' => $structured,
        ]);

        self::assertSame($structured, $result['task_plan_structured']);
        self::assertSame($structured, $result['virtual_theme_plan']['draft']);
        self::assertSame(1, (int)$result['task_plan_summary']['shared_task_count']);
        self::assertSame(1, (int)$result['task_plan_summary']['page_task_count']);
        self::assertSame('manual_structured_edit', (string)$result['task_plan_summary']['generation_source']);
    }

    public function testDraftOnlyStructuredTaskPlanStillWritesTaskPlanStructured(): void
    {
        $draft = [
            'page_tasks' => [
                Page::TYPE_HOME => [
                    ['task_key' => 'page:home:hero'],
                    ['task_key' => 'page:home:trust'],
                ],
            ],
        ];

        $result = $this->normalizePayload([
            'virtual_theme_plan' => [
                'draft' => $draft,
                'draft_generated_at' => '2026-04-21 10:00:00',
            ],
        ]);

        self::assertSame($draft, $result['task_plan_structured']);
        self::assertSame($draft, $result['virtual_theme_plan']['draft']);
        self::assertSame(0, (int)$result['task_plan_summary']['shared_task_count']);
        self::assertSame(2, (int)$result['task_plan_summary']['page_task_count']);
        self::assertSame('2026-04-21 10:00:00', (string)$result['virtual_theme_plan']['draft_generated_at']);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload): array
    {
        $reflection = new \ReflectionClass(AiSiteAgent::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('normalizeTaskPlanStructuredScopePayload');
        $method->setAccessible(true);
        $result = $method->invoke($controller, $payload);

        self::assertIsArray($result);
        return $result;
    }
}
