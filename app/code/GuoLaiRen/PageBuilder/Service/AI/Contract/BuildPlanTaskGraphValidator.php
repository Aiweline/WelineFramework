<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Contract;

final class BuildPlanTaskGraphValidator
{
    /**
     * @param array<string, mixed> $contract
     * @return array{valid:bool,errors:list<string>}
     */
    public function validate(array $contract): array
    {
        $errors = [];
        [$tasksById, $taskErrors] = $this->indexById($contract['tasks'] ?? [], ['task_id', 'id'], 'tasks');
        [$pagesById, $pageErrors] = $this->indexById($contract['pages'] ?? [], ['page_id', 'id'], 'pages');
        [$blocksById, $blockErrors] = $this->indexById($contract['blocks'] ?? [], ['block_id', 'id'], 'blocks');
        $errors = \array_merge($errors, $taskErrors, $pageErrors, $blockErrors);

        $graph = [];
        foreach ($tasksById as $taskId => $task) {
            $deps = $this->stringList($task['depends_on'] ?? []);
            $graph[$taskId] = $deps;
            foreach ($deps as $depId) {
                if (!isset($tasksById[$depId])) {
                    $errors[] = 'Task ' . $taskId . ' depends_on missing task: ' . $depId;
                }
            }
        }

        $cycle = $this->findCycle($graph);
        if ($cycle !== []) {
            $errors[] = 'Task graph has a cycle: ' . \implode(' -> ', $cycle);
        }

        $buildOrder = $this->normalizeBuildOrder($contract['build_order'] ?? []);
        $taskIds = \array_keys($tasksById);
        $missingFromOrder = \array_values(\array_diff($taskIds, $buildOrder));
        foreach ($missingFromOrder as $taskId) {
            $errors[] = 'build_order is missing task: ' . $taskId;
        }
        $unknownInOrder = \array_values(\array_diff($buildOrder, $taskIds));
        foreach ($unknownInOrder as $taskId) {
            $errors[] = 'build_order contains unknown task: ' . $taskId;
        }
        $positions = \array_flip($buildOrder);
        foreach ($graph as $taskId => $deps) {
            foreach ($deps as $depId) {
                if (isset($positions[$taskId], $positions[$depId]) && $positions[$depId] > $positions[$taskId]) {
                    $errors[] = 'build_order places task before dependency: ' . $taskId . ' before ' . $depId;
                }
            }
        }

        foreach ($pagesById as $pageId => $page) {
            $blockRefs = $this->extractPageBlockRefs($page);
            if ($blockRefs === []) {
                $errors[] = 'Page has no blocks: ' . $pageId;
            }
            foreach ($blockRefs as $blockId) {
                if (!isset($blocksById[$blockId])) {
                    $errors[] = 'Page ' . $pageId . ' references missing block: ' . $blockId;
                }
            }
        }

        foreach ($blocksById as $blockId => $block) {
            $pageId = \trim((string)($block['page_id'] ?? ''));
            if ($pageId === '' || !isset($pagesById[$pageId])) {
                $errors[] = 'Block ' . $blockId . ' references missing page: ' . ($pageId !== '' ? $pageId : '(empty)');
            }
            $taskRefs = $this->extractBlockTaskRefs($block, $blockId, $tasksById);
            if ($taskRefs === []) {
                $errors[] = 'Block has no task: ' . $blockId;
            }
            foreach ($taskRefs as $taskId) {
                if (!isset($tasksById[$taskId])) {
                    $errors[] = 'Block ' . $blockId . ' references missing task: ' . $taskId;
                }
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => \array_values(\array_unique($errors)),
        ];
    }

    /**
     * @param mixed $items
     * @param list<string> $idFields
     * @return array{0:array<string,array<string,mixed>>,1:list<string>}
     */
    private function indexById(mixed $items, array $idFields, string $label): array
    {
        if (!\is_array($items)) {
            return [[], [$label . ' must be an array']];
        }

        $indexed = [];
        $errors = [];
        foreach ($items as $key => $item) {
            if (!\is_array($item)) {
                $errors[] = $label . '[' . (string)$key . '] must be an object';
                continue;
            }
            $id = '';
            foreach ($idFields as $field) {
                $id = \trim((string)($item[$field] ?? ''));
                if ($id !== '') {
                    break;
                }
            }
            if ($id === '' && \is_string($key) && $key !== '') {
                $id = $key;
            }
            if ($id === '') {
                $errors[] = $label . '[' . (string)$key . '] is missing id';
                continue;
            }
            if (isset($indexed[$id])) {
                $errors[] = $label . ' contains duplicate id: ' . $id;
                continue;
            }
            $indexed[$id] = $item;
        }

        return [$indexed, $errors];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $values): array
    {
        if (!\is_array($values)) {
            return [];
        }

        $result = [];
        foreach ($values as $value) {
            $text = \trim((string)$value);
            if ($text !== '') {
                $result[] = $text;
            }
        }

        return \array_values(\array_unique($result));
    }

    /**
     * @return list<string>
     */
    private function normalizeBuildOrder(mixed $buildOrder): array
    {
        if (!\is_array($buildOrder)) {
            return [];
        }

        $result = [];
        foreach ($buildOrder as $item) {
            if (\is_array($item)) {
                $item = $item['task_id'] ?? $item['id'] ?? '';
            }
            $taskId = \trim((string)$item);
            if ($taskId !== '') {
                $result[] = $taskId;
            }
        }

        return \array_values(\array_unique($result));
    }

    /**
     * @param array<string, list<string>> $graph
     * @return list<string>
     */
    private function findCycle(array $graph): array
    {
        $state = [];
        $stack = [];
        foreach (\array_keys($graph) as $taskId) {
            $cycle = $this->visit($taskId, $graph, $state, $stack);
            if ($cycle !== []) {
                return $cycle;
            }
        }

        return [];
    }

    /**
     * @param array<string, list<string>> $graph
     * @param array<string, int> $state
     * @param list<string> $stack
     * @return list<string>
     */
    private function visit(string $taskId, array $graph, array &$state, array &$stack): array
    {
        if (($state[$taskId] ?? 0) === 2) {
            return [];
        }
        if (($state[$taskId] ?? 0) === 1) {
            $start = \array_search($taskId, $stack, true);
            $cycle = $start === false ? [$taskId] : \array_slice($stack, (int)$start);
            $cycle[] = $taskId;
            return $cycle;
        }

        $state[$taskId] = 1;
        $stack[] = $taskId;
        foreach ($graph[$taskId] ?? [] as $depId) {
            if (!isset($graph[$depId])) {
                continue;
            }
            $cycle = $this->visit($depId, $graph, $state, $stack);
            if ($cycle !== []) {
                return $cycle;
            }
        }
        \array_pop($stack);
        $state[$taskId] = 2;

        return [];
    }

    /**
     * @param array<string, mixed> $page
     * @return list<string>
     */
    private function extractPageBlockRefs(array $page): array
    {
        $source = \is_array($page['blocks'] ?? null) ? $page['blocks'] : (\is_array($page['block_ids'] ?? null) ? $page['block_ids'] : []);
        $refs = [];
        foreach ($source as $item) {
            if (\is_array($item)) {
                $item = $item['block_id'] ?? $item['id'] ?? '';
            }
            $blockId = \trim((string)$item);
            if ($blockId !== '') {
                $refs[] = $blockId;
            }
        }

        return \array_values(\array_unique($refs));
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, array<string, mixed>> $tasksById
     * @return list<string>
     */
    private function extractBlockTaskRefs(array $block, string $blockId, array $tasksById): array
    {
        $source = \is_array($block['task_ids'] ?? null) ? $block['task_ids'] : (\is_array($block['tasks'] ?? null) ? $block['tasks'] : []);
        $refs = [];
        foreach ($source as $item) {
            if (\is_array($item)) {
                $item = $item['task_id'] ?? $item['id'] ?? '';
            }
            $taskId = \trim((string)$item);
            if ($taskId !== '') {
                $refs[] = $taskId;
            }
        }
        if ($refs !== []) {
            return \array_values(\array_unique($refs));
        }

        foreach ($tasksById as $taskId => $task) {
            $scope = \is_array($task['input_scope'] ?? null) ? $task['input_scope'] : [];
            $candidate = \trim((string)($task['block_id'] ?? $scope['block_id'] ?? ''));
            if ($candidate === $blockId) {
                $refs[] = $taskId;
            }
        }

        return \array_values(\array_unique($refs));
    }
}
