<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Theme\Model\ThemeVirtualLayout;

/**
 * Resolve effective product layout_option: schedule → product → category default → default.
 */
final class ProductLayoutResolveService
{
    public function __construct(
        private readonly ThemeVirtualLayoutService $selections,
        private readonly ProductLayoutScheduleService $schedules,
        private readonly ProductLayoutOptionService $options,
    ) {
    }

    /**
     * @param list<int> $categoryIds Preferred path category first, then others
     * @return array{
     *   layout_option:string,
     *   source:string,
     *   schedule_id:int,
     *   target_type:string,
     *   target_id:int,
     *   fallback_chain:list<string>
     * }
     */
    public function resolveForProduct(
        int $productId,
        array $categoryIds = [],
        ?string $scope = null,
        ?string $locale = null,
        ?\DateTimeInterface $now = null,
        int $websiteId = 0,
    ): array {
        $chain = [];
        $productId = max(0, $productId);

        if ($productId > 0) {
            $scheduled = $this->schedules->resolveActive(
                ThemeVirtualLayout::TARGET_PRODUCT,
                $productId,
                ProductLayoutOptionService::LAYOUT_TYPE,
                $scope,
                $now,
                $websiteId,
            );
            if ($scheduled !== null) {
                $chain[] = 'schedule:product';

                return $this->result(
                    (string)$scheduled['layout_option'],
                    'schedule',
                    (int)$scheduled['schedule_id'],
                    ThemeVirtualLayout::TARGET_PRODUCT,
                    $productId,
                    $chain,
                );
            }
        }

        foreach ($categoryIds as $categoryId) {
            $categoryId = (int)$categoryId;
            if ($categoryId <= 0) {
                continue;
            }
            $scheduled = $this->schedules->resolveActive(
                ThemeVirtualLayout::TARGET_CATEGORY_PRODUCT_DEFAULT,
                $categoryId,
                ProductLayoutOptionService::LAYOUT_TYPE,
                $scope,
                $now,
                $websiteId,
            );
            if ($scheduled !== null) {
                $chain[] = 'schedule:category_product_default:' . $categoryId;

                return $this->result(
                    (string)$scheduled['layout_option'],
                    'schedule',
                    (int)$scheduled['schedule_id'],
                    ThemeVirtualLayout::TARGET_CATEGORY_PRODUCT_DEFAULT,
                    $categoryId,
                    $chain,
                );
            }
        }

        if ($productId > 0) {
            $selection = $this->selections->resolveLayoutSelection(
                ThemeVirtualLayout::TARGET_PRODUCT,
                $productId,
                ProductLayoutOptionService::LAYOUT_TYPE,
                $scope,
                $locale,
            );
            if (is_array($selection) && ($selection['layout_option'] ?? '') !== '') {
                $chain[] = 'selection:product';

                return $this->result(
                    (string)$selection['layout_option'],
                    'product',
                    0,
                    ThemeVirtualLayout::TARGET_PRODUCT,
                    $productId,
                    $chain,
                );
            }
            $chain[] = 'selection:product:miss';
        }

        foreach ($categoryIds as $categoryId) {
            $categoryId = (int)$categoryId;
            if ($categoryId <= 0) {
                continue;
            }
            $selection = $this->selections->resolveLayoutSelection(
                ThemeVirtualLayout::TARGET_CATEGORY_PRODUCT_DEFAULT,
                $categoryId,
                ProductLayoutOptionService::LAYOUT_TYPE,
                $scope,
                $locale,
            );
            if (is_array($selection) && ($selection['layout_option'] ?? '') !== '') {
                $chain[] = 'selection:category_product_default:' . $categoryId;

                return $this->result(
                    (string)$selection['layout_option'],
                    'category_product_default',
                    0,
                    ThemeVirtualLayout::TARGET_CATEGORY_PRODUCT_DEFAULT,
                    $categoryId,
                    $chain,
                );
            }
            $chain[] = 'selection:category_product_default:' . $categoryId . ':miss';
        }

        $chain[] = 'file:default';

        return $this->result('default', 'file', 0, ThemeVirtualLayout::TARGET_GLOBAL, 0, $chain);
    }

    /**
     * @param list<string> $fallbackChain
     * @return array{
     *   layout_option:string,
     *   source:string,
     *   schedule_id:int,
     *   target_type:string,
     *   target_id:int,
     *   fallback_chain:list<string>
     * }
     */
    private function result(
        string $layoutOption,
        string $source,
        int $scheduleId,
        string $targetType,
        int $targetId,
        array $fallbackChain,
    ): array {
        $layoutOption = $this->selections->normalizeLayoutOption($layoutOption);
        if ($layoutOption === '' || !$this->options->optionExists($layoutOption)) {
            $layoutOption = 'default';
            $fallbackChain[] = 'sanitize:default';
        }

        return [
            'layout_option' => $layoutOption,
            'source' => $source,
            'schedule_id' => max(0, $scheduleId),
            'target_type' => $targetType,
            'target_id' => max(0, $targetId),
            'fallback_chain' => $fallbackChain,
        ];
    }
}
