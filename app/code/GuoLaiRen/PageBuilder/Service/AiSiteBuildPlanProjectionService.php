<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

final class AiSiteBuildPlanProjectionService
{
    /**
     * @param array<string, mixed> $contract
     * @return array<string, mixed>
     */
    public function build(array $contract): array
    {
        $meta = \is_array($contract['contract_meta'] ?? null) ? $contract['contract_meta'] : [];
        $brief = \is_array($contract['site_brief'] ?? null) ? $contract['site_brief'] : [];
        $content = \is_array($contract['content_manifest'] ?? null) ? $contract['content_manifest'] : [];
        $items = \is_array($content['items'] ?? null) ? $content['items'] : [];
        $pages = $this->normalizeRecordSet($contract['pages'] ?? [], ['page_id', 'id']);
        $blocks = $this->normalizeRecordSet($contract['blocks'] ?? [], ['block_id', 'id']);
        $tasks = $this->normalizeRecordSet($contract['tasks'] ?? [], ['task_id', 'id']);
        $design = \is_array($contract['design_manifest'] ?? null) ? $contract['design_manifest'] : [];
        $policyProjection = \is_array($contract['policy_projection'] ?? null) ? $contract['policy_projection'] : [];

        $projectedPages = [];
        foreach ($pages as $pageId => $page) {
            $pageBlockIds = $this->stringList($page['blocks'] ?? $page['block_ids'] ?? []);
            $projectedBlocks = [];
            foreach ($pageBlockIds as $blockId) {
                $block = \is_array($blocks[$blockId] ?? null) ? $blocks[$blockId] : [];
                if ($block === []) {
                    continue;
                }
                $projectedBlocks[] = [
                    'block_id' => $blockId,
                    'type' => (string)($block['block_type'] ?? ''),
                    'title' => $this->firstContentValue($items, $this->stringList($block['content_keys'] ?? [])),
                    'task_count' => \count($this->stringList($block['task_ids'] ?? [])),
                ];
            }

            $projectedPages[] = [
                'page_id' => $pageId,
                'page_type' => (string)($page['page_type'] ?? $pageId),
                'title' => $this->contentValue($items, (string)($page['title_key'] ?? ''), (string)($page['title'] ?? $pageId)),
                'description' => $this->contentValue($items, (string)($page['description_key'] ?? ''), (string)($page['description'] ?? '')),
                'blocks' => $projectedBlocks,
            ];
        }

        return [
            'version' => '1.0',
            'source_contract_id' => (string)($meta['id'] ?? ''),
            'source_contract_version' => (string)($meta['version'] ?? ''),
            'source_contract_status' => (string)($meta['status'] ?? ''),
            'never_feed_to_build' => true,
            'site_name' => (string)($brief['site_name'] ?? $brief['site_title'] ?? ''),
            'primary_goal' => (string)($brief['primary_goal'] ?? ''),
            'summary' => (string)($brief['summary'] ?? $brief['primary_goal'] ?? ''),
            'page_count' => \count($projectedPages),
            'block_count' => \count($blocks),
            'task_count' => \count($tasks),
            'pages' => $projectedPages,
            'design' => [
                'policy_id' => (string)($contract['policy_ref']['policy_id'] ?? ''),
                'quality_floor' => \is_array($policyProjection['quality_floor'] ?? null) ? $policyProjection['quality_floor'] : [],
                'tokens' => \is_array($design['tokens'] ?? null) ? $design['tokens'] : [],
            ],
        ];
    }

    /**
     * @param list<string> $idFields
     * @return array<string, array<string, mixed>>
     */
    private function normalizeRecordSet(mixed $items, array $idFields): array
    {
        if (!\is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $key => $item) {
            if (!\is_array($item)) {
                continue;
            }
            $id = '';
            foreach ($idFields as $field) {
                $id = \trim((string)($item[$field] ?? ''));
                if ($id !== '') {
                    break;
                }
            }
            if ($id === '' && \is_string($key)) {
                $id = $key;
            }
            if ($id !== '') {
                $normalized[$id] = $item;
            }
        }

        return $normalized;
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
            if (\is_array($value)) {
                $value = $value['block_id'] ?? $value['task_id'] ?? $value['id'] ?? '';
            }
            $text = \trim((string)$value);
            if ($text !== '') {
                $result[] = $text;
            }
        }

        return \array_values(\array_unique($result));
    }

    /**
     * @param array<string, mixed> $items
     */
    private function firstContentValue(array $items, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $this->contentValue($items, $key, '');
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $items
     */
    private function contentValue(array $items, string $key, string $fallback): string
    {
        $key = \trim($key);
        if ($key === '' || !\array_key_exists($key, $items)) {
            return $fallback;
        }

        $value = $items[$key];
        if (\is_scalar($value) || (\is_object($value) && \method_exists($value, '__toString'))) {
            return \trim((string)$value);
        }
        if (!\is_array($value)) {
            return $fallback;
        }

        foreach (['text', 'value', 'copy', 'content'] as $field) {
            if (\array_key_exists($field, $value) && (\is_scalar($value[$field]) || (\is_object($value[$field]) && \method_exists($value[$field], '__toString')))) {
                return \trim((string)$value[$field]);
            }
        }

        return $fallback;
    }
}
