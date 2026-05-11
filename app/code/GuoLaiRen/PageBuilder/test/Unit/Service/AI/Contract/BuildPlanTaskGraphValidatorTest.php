<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service\AI\Contract;

use GuoLaiRen\PageBuilder\Service\AI\Contract\BuildPlanTaskGraphValidator;
use PHPUnit\Framework\TestCase;

final class BuildPlanTaskGraphValidatorTest extends TestCase
{
    public function testValidTaskGraphPasses(): void
    {
        $result = (new BuildPlanTaskGraphValidator())->validate($this->contract([
            ['task_id' => 'task.asset', 'depends_on' => []],
            ['task_id' => 'task.hero', 'depends_on' => ['task.asset']],
        ], ['task.asset', 'task.hero']));

        self::assertTrue($result['valid'], \implode("\n", $result['errors']));
    }

    public function testRejectsCycles(): void
    {
        $result = (new BuildPlanTaskGraphValidator())->validate($this->contract([
            ['task_id' => 'task.a', 'depends_on' => ['task.b']],
            ['task_id' => 'task.b', 'depends_on' => ['task.a']],
        ], ['task.a', 'task.b']));

        self::assertFalse($result['valid']);
        self::assertTrue($this->hasErrorContaining($result['errors'], 'cycle'));
    }

    public function testRejectsBuildOrderBeforeDependency(): void
    {
        $result = (new BuildPlanTaskGraphValidator())->validate($this->contract([
            ['task_id' => 'task.asset', 'depends_on' => []],
            ['task_id' => 'task.hero', 'depends_on' => ['task.asset']],
        ], ['task.hero', 'task.asset']));

        self::assertFalse($result['valid']);
        self::assertTrue($this->hasErrorContaining($result['errors'], 'before dependency'));
    }

    /**
     * @param list<array{task_id:string,depends_on:list<string>}> $tasks
     * @param list<string> $buildOrder
     * @return array<string, mixed>
     */
    private function contract(array $tasks, array $buildOrder): array
    {
        $normalizedTasks = [];
        foreach ($tasks as $task) {
            $normalizedTasks[] = \array_replace([
                'task_kind' => 'block_build',
                'executor' => 'AiSiteBuildQueue',
                'input_scope' => ['page_id' => 'home', 'block_id' => 'home.hero'],
                'policy_slices' => ['layout.4_8_spacing'],
                'context_budget' => ['max_tokens' => 1200],
                'acceptance_rule_ids' => ['responsive.no_horizontal_scroll'],
            ], $task);
        }

        return [
            'pages' => [
                ['page_id' => 'home', 'blocks' => ['home.hero']],
            ],
            'blocks' => [
                ['block_id' => 'home.hero', 'page_id' => 'home', 'task_ids' => \array_column($tasks, 'task_id')],
            ],
            'tasks' => $normalizedTasks,
            'build_order' => $buildOrder,
        ];
    }

    /**
     * @param list<string> $errors
     */
    private function hasErrorContaining(array $errors, string $needle): bool
    {
        foreach ($errors as $error) {
            if (\str_contains($error, $needle)) {
                return true;
            }
        }

        return false;
    }
}
