<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Contract;

final class PlanJsonBlockGraphValidator
{
    /**
     * @param array<string, mixed> $contract
     * @return array{valid:bool,errors:list<string>}
     */
    public function validate(array $contract): array
    {
        $errors = [];
        [$pagesById, $pageErrors] = $this->indexById($contract['pages'] ?? [], ['page_id', 'id'], 'pages');
        [$blocksById, $blockErrors] = $this->indexById($contract['block_nodes'] ?? [], ['block_id', 'id'], 'block_nodes');
        $errors = \array_merge($errors, $pageErrors, $blockErrors);

        foreach ($pagesById as $pageId => $page) {
            $blockRefs = $this->extractPageBlockRefs($page);
            if ($blockRefs === []) {
                $errors[] = 'Page has no block_nodes: ' . $pageId;
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
     * @param array<string, mixed> $page
     * @return list<string>
     */
    private function extractPageBlockRefs(array $page): array
    {
        $source = \is_array($page['block_node_ids'] ?? null) ? $page['block_node_ids'] : [];
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
}
