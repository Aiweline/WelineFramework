<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Controller;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use PHPUnit\Framework\TestCase;

/**
 * 覆盖 AiSiteAgent::deriveTaskPlanArtifactsFromExecutionBlueprint。
 *
 * 第二阶段任务方案确认后，task_plan_structured / virtual_theme_plan.draft|confirmed
 * 全部被清空，真实数据沉到 execution_blueprint。这套测试锁定派生函数始终能把
 * 蓝图反推成前端期望的 {shared_tasks, page_tasks} 形态及概览 markdown。
 */
final class AiSiteAgentExecutionBlueprintDeriveTest extends TestCase
{
    public function testEmptyTasksReturnEmptyArtifacts(): void
    {
        $result = $this->derive([]);

        self::assertSame([], $result['structured']);
        self::assertSame('', $result['markdown']);
    }

    public function testSharedComponentTaskGoesIntoSharedTasks(): void
    {
        $result = $this->derive([
            'tasks' => [
                [
                    'task_key' => 'shared:header',
                    'task_type' => 'shared_component',
                    'block_key' => 'shared:header',
                    'component' => 'header',
                    'title' => 'Site header',
                    'goal' => 'Render brand-aligned global navigation.',
                    'style_brief' => 'Light theme with sticky behaviour.',
                    'seo_brief' => 'Expose canonical nav links.',
                    'completion_rule' => ['Logo visible', 'Primary nav links present'],
                ],
            ],
        ]);

        self::assertArrayHasKey('shared_tasks', $result['structured']);
        self::assertCount(1, $result['structured']['shared_tasks']);
        $task = $result['structured']['shared_tasks'][0];
        self::assertSame('shared:header', $task['task_key']);
        self::assertSame('Site header', $task['label']);
        self::assertSame('Render brand-aligned global navigation.', $task['plan_context']['block_goal']);
        self::assertSame('Light theme with sticky behaviour.', $task['task_script']['content_fill_rule']);
        self::assertSame(['Logo visible', 'Primary nav links present'], $task['implementation_contract']['acceptance']);
    }

    public function testPageBlockTaskGroupsByPageTypeAndUsesNestedBlockFields(): void
    {
        $result = $this->derive([
            'tasks' => [
                [
                    'task_key' => 'page:home_page:hero_block',
                    'task_type' => 'page_block',
                    'page_type' => 'home_page',
                    'page_label' => '首页',
                    'block' => [
                        'block_key' => 'hero_block',
                        'title' => 'Hero section',
                        'goal' => 'Lead the visitor with a clear value proposition.',
                        'style_direction' => 'Bold typography on dark background',
                        'seo_role' => 'Primary H1 with target keyword',
                        'why' => 'Capture attention above the fold',
                        'field_plan' => [
                            ['field' => 'headline', 'sample' => 'Trusted by enterprises'],
                        ],
                        'content' => ['headline' => 'Build faster'],
                    ],
                ],
            ],
        ]);

        self::assertArrayHasKey('page_tasks', $result['structured']);
        self::assertArrayHasKey('home_page', $result['structured']['page_tasks']);
        $tasks = $result['structured']['page_tasks']['home_page'];
        self::assertCount(1, $tasks);
        $task = $tasks[0];
        self::assertSame('page:home_page:hero_block', $task['task_key']);
        self::assertSame('Hero section', $task['label']);
        self::assertSame('hero_block', $task['group_key']);
        self::assertSame('首页', $task['plan_context']['page_goal']);
        self::assertSame('Lead the visitor with a clear value proposition.', $task['plan_context']['block_goal']);
        self::assertSame('Bold typography on dark background', $task['task_script']['content_fill_rule']);
        self::assertSame('Capture attention above the fold', $task['planning_reason']);
        self::assertSame([['field' => 'headline', 'sample' => 'Trusted by enterprises']], $task['task_script']['field_content_requirements']);
        self::assertSame(['headline' => 'Build faster'], $task['block_task']['content_plan']);
    }

    public function testPageTypeIsParsedFromTaskKeyWhenMissing(): void
    {
        $result = $this->derive([
            'tasks' => [
                [
                    'task_key' => 'page:about_page:about_story',
                    'task_type' => 'page_block',
                    'block' => [
                        'block_key' => 'about_story',
                    ],
                ],
            ],
        ]);

        self::assertArrayHasKey('about_page', $result['structured']['page_tasks']);
        self::assertSame('about_story', $result['structured']['page_tasks']['about_page'][0]['group_key']);
    }

    public function testMarkdownContainsSharedAndPageSections(): void
    {
        $result = $this->derive([
            'tasks' => [
                [
                    'task_key' => 'shared:header',
                    'task_type' => 'shared_component',
                    'title' => 'Site header',
                    'goal' => 'Global navigation',
                ],
                [
                    'task_key' => 'page:home_page:hero_block',
                    'task_type' => 'page_block',
                    'page_type' => 'home_page',
                    'page_label' => '首页',
                    'block' => ['title' => 'Hero', 'goal' => 'Lead with value'],
                ],
            ],
        ]);

        $md = $result['markdown'];
        self::assertStringContainsString('# 第二阶段任务方案', $md);
        self::assertStringContainsString('## 共享组件任务', $md);
        self::assertStringContainsString('Site header', $md);
        self::assertStringContainsString('首页 (home_page)', $md);
        self::assertStringContainsString('Hero', $md);
    }

    /**
     * @param array<string, mixed> $blueprint
     * @return array{structured: array<string, mixed>, markdown: string}
     */
    private function derive(array $blueprint): array
    {
        $reflection = new \ReflectionClass(AiSiteAgent::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('deriveTaskPlanArtifactsFromExecutionBlueprint');
        $method->setAccessible(true);
        $result = $method->invoke($controller, $blueprint);

        self::assertIsArray($result);
        self::assertArrayHasKey('structured', $result);
        self::assertArrayHasKey('markdown', $result);
        return $result;
    }
}
