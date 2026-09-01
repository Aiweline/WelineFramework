<?php

declare(strict_types=1);

namespace Weline\Compare\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;

/**
 * 对比页视图数据：在内容模板渲染阶段解析（晚于 Theme fetch_file_before / Cookie 作用域）。
 */
final class ComparePagePresenter
{
    public function __construct(
        private readonly CompareService $compare,
        private readonly CompareSpecificationMatrix $specMatrix,
    ) {
    }

    /**
     * @return array{
     *     success: bool,
     *     items: list<array<string, mixed>>,
     *     count: int,
     *     max: int,
     *     specRows: list<array<string, mixed>>,
     *     ratingLabelClass: string,
     *     ratingCellClasses: list<string>,
     *     priceCellClasses: list<string>
     * }
     */
    public function resolveViewModel(): array
    {
        $payload = $this->compare->list();
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $specRows = $this->specMatrix->buildRows($items);

        $ratingValues = array_map(
            static fn (array $item): string => number_format((float)($item['rating'] ?? 0), 1)
                . ' (' . (int)($item['review_count'] ?? 0) . ')',
            $items,
        );

        $ratingCellClasses = [];
        foreach ($items as $index => $item) {
            unset($item);
            $ratingCellClasses[] = trim(
                $this->specMatrix->cellIsHighlight($ratingValues[$index] ?? '', $ratingValues)
                    ? 'storefront-compare__cell--hit'
                    : '',
            );
        }

        $priceCellClasses = [];
        foreach ($items as $index => $item) {
            unset($item);
            $priceCellClasses[] = $this->specMatrix->priceCellIsHighlight($index, $items)
                ? 'storefront-compare__cell--best-price'
                : '';
        }

        $enrichedSpecRows = [];
        foreach ($specRows as $specRow) {
            $cellClasses = [];
            $values = is_array($specRow['values'] ?? null) ? $specRow['values'] : [];
            foreach ($values as $index => $value) {
                unset($value);
                $cellClasses[] = trim($this->specMatrix->specCellHighlightClass($index, $specRow));
            }
            $enrichedSpecRows[] = $specRow + [
                'label_class' => trim($this->specMatrix->specLabelHighlightClass($specRow)),
                'cell_classes' => $cellClasses,
            ];
        }

        return [
            'success' => true,
            'items' => $items,
            'count' => (int)($payload['compare_count'] ?? count($items)),
            'max' => (int)($payload['max'] ?? CompareSessionStore::MAX_ITEMS),
            'specRows' => $enrichedSpecRows,
            'ratingLabelClass' => trim(
                $this->specMatrix->labelIsHighlight($ratingValues) ? 'storefront-compare__label--hit' : '',
            ),
            'ratingCellClasses' => $ratingCellClasses,
            'priceCellClasses' => $priceCellClasses,
        ];
    }

    /**
     * @return array{
     *     items: list<array<string, mixed>>,
     *     count: int,
     *     max: int,
     *     specRows: list<array{code: string, label: string, values: list<string>, differs: bool, compare_mode: string}>
     * }
     */
    public function resolve(?Template $template = null): array
    {
        $template ??= ObjectManager::getInstance(Template::class);
        unset($template);

        $view = $this->resolveViewModel();

        return [
            'items' => $view['items'],
            'count' => $view['count'],
            'max' => $view['max'],
            'specRows' => $view['specRows'],
        ];
    }
}
