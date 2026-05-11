<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

final class AiSiteBuildPromptContextAssembler
{
    /**
     * @param array<string, mixed> $contract
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    public function assemble(array $contract, array $task): array
    {
        $inputScope = \is_array($task['input_scope'] ?? null) ? $task['input_scope'] : [];
        $blockId = \trim((string)($inputScope['block_id'] ?? $task['block_id'] ?? ''));
        $pageId = \trim((string)($inputScope['page_id'] ?? $task['page_id'] ?? ''));
        $blocks = $this->normalizeRecordSet($contract['blocks'] ?? [], ['block_id', 'id']);
        $pages = $this->normalizeRecordSet($contract['pages'] ?? [], ['page_id', 'id']);
        $contentManifest = \is_array($contract['content_manifest'] ?? null) ? $contract['content_manifest'] : [];
        $items = \is_array($contentManifest['items'] ?? null) ? $contentManifest['items'] : [];
        $block = \is_array($blocks[$blockId] ?? null) ? $blocks[$blockId] : [];
        $page = \is_array($pages[$pageId] ?? null) ? $pages[$pageId] : [];

        return [
            'contract_id' => (string)($contract['contract_meta']['id'] ?? ''),
            'task' => $task,
            'page' => $page,
            'block' => $block,
            'content_items' => $this->sliceContentItems($items, $this->stringList($block['content_keys'] ?? [])),
            'design_manifest' => \is_array($contract['design_manifest'] ?? null) ? $contract['design_manifest'] : [],
            'policy_ref' => \is_array($contract['policy_ref'] ?? null) ? $contract['policy_ref'] : [],
            'policy_projection' => \is_array($contract['policy_projection'] ?? null) ? $contract['policy_projection'] : [],
            'policy_slices' => $this->stringList($task['policy_slices'] ?? []),
            'acceptance_rule_ids' => $this->stringList($task['acceptance_rule_ids'] ?? []),
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
            $text = \trim((string)$value);
            if ($text !== '') {
                $result[] = $text;
            }
        }

        return \array_values(\array_unique($result));
    }

    /**
     * @param array<string, mixed> $items
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    private function sliceContentItems(array $items, array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            if (\array_key_exists($key, $items)) {
                $result[$key] = $items[$key];
            }
        }

        return $result;
    }
}
