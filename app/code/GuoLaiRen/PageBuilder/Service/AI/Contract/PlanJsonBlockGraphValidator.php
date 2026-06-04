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
        $errors = \array_merge($errors, $pageErrors);

        foreach ($pagesById as $pageId => $page) {
            $blocks = $this->extractPageBlocks($page);
            if ($blocks === []) {
                $errors[] = 'Page has no dynamic blocks: ' . $pageId;
            }
            foreach ($blocks as $blockKey => $block) {
                $blockPageId = \trim((string)($block['page_id'] ?? $pageId));
                if ($blockPageId !== '' && $blockPageId !== $pageId && !isset($pagesById[$blockPageId])) {
                    $errors[] = 'Block ' . $blockKey . ' references missing page: ' . $blockPageId;
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
     * @param array<string, mixed> $page
     * @return list<string>
     */
    private function extractPageBlocks(array $page): array
    {
        $reserved = [
            'page_id' => true,
            'id' => true,
            'page_type' => true,
            'type' => true,
            'title' => true,
            'description' => true,
            'page_goal' => true,
            'page_design_plan' => true,
            'theme_alignment_summary' => true,
            'status' => true,
            'seo' => true,
            'route' => true,
            'meta' => true,
            'layout' => true,
            'blocks' => true,
            'block_previews' => true,
            'sections' => true,
            'components' => true,
        ];
        $blocks = [];
        foreach ($page as $key => $value) {
            if (!\is_string($key) || isset($reserved[$key]) || !\is_array($value)) {
                continue;
            }
            $blocks[$key] = $value;
        }

        return $blocks;
    }
}
