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
     *     items: list<array<string, mixed>>,
     *     count: int,
     *     max: int,
     *     specRows: list<array{code: string, label: string, values: list<string>, differs: bool, compare_mode: string}>
     * }
     */
    public function resolve(?Template $template = null): array
    {
        $template ??= ObjectManager::getInstance(Template::class);

        $payload = $this->compare->list();
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];

        return [
            'items' => $items,
            'count' => (int)($payload['compare_count'] ?? count($items)),
            'max' => (int)($payload['max'] ?? CompareSessionStore::MAX_ITEMS),
            'specRows' => $this->specMatrix->buildRows($items),
        ];
    }
}
