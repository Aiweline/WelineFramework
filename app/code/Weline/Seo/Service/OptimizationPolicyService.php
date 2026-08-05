<?php

declare(strict_types=1);

namespace Weline\Seo\Service;

use Weline\Seo\Model\SeoOptimizationPolicy;

final class OptimizationPolicyService
{
    public function __construct(private readonly SeoOptimizationPolicy $policyModel)
    {
    }

    /** @return array<string,mixed> */
    public function get(int $websiteId): array
    {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException('website_id must be non-negative.');
        }
        $model = clone $this->policyModel;
        $model->clearData()->clearQuery();
        $model->where(SeoOptimizationPolicy::schema_fields_WEBSITE_ID, $websiteId)->find()->fetch();
        $data = $model->getId() > 0 ? (array)$model->getData() : [];

        return \array_replace($this->defaults($websiteId), $data);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function save(int $websiteId, array $input): array
    {
        $current = $this->get($websiteId);
        $mode = \strtolower(\trim((string)($input['mode'] ?? $current['mode'])));
        if (!\in_array($mode, SeoOptimizationPolicy::modes(), true)) {
            throw new \InvalidArgumentException('Optimization mode is invalid.');
        }
        $standing = $this->flag($input['standing_authorized'] ?? $current['standing_authorized']);
        if ($mode === SeoOptimizationPolicy::MODE_AUTO_PUBLISH && !$standing) {
            throw new \InvalidArgumentException('auto_publish requires standing authorization.');
        }
        $model = clone $this->policyModel;
        $model->clearData()->clearQuery();
        $model->where(SeoOptimizationPolicy::schema_fields_WEBSITE_ID, $websiteId)->find()->fetch();
        $data = [
            SeoOptimizationPolicy::schema_fields_WEBSITE_ID => $websiteId,
            SeoOptimizationPolicy::schema_fields_MODE => $mode,
            SeoOptimizationPolicy::schema_fields_STANDING_AUTHORIZED => $standing ? 1 : 0,
            SeoOptimizationPolicy::schema_fields_MIN_PAGE_VIEWS => $this->bounded($input, 'min_page_views', (int)$current['min_page_views'], 1, 10000000),
            SeoOptimizationPolicy::schema_fields_MIN_CONVERSIONS => $this->bounded($input, 'min_conversions', (int)$current['min_conversions'], 1, 1000000),
            SeoOptimizationPolicy::schema_fields_MIN_SEARCH_IMPRESSIONS => $this->bounded($input, 'min_search_impressions', (int)$current['min_search_impressions'], 1, 100000000),
            SeoOptimizationPolicy::schema_fields_MIN_CONFIDENCE => \max(0.8, \min(1.0, (float)($input['min_confidence'] ?? $current['min_confidence']))),
            SeoOptimizationPolicy::schema_fields_MIN_UPLIFT_BPS => $this->bounded($input, 'min_uplift_bps', (int)$current['min_uplift_bps'], 0, 10000),
            SeoOptimizationPolicy::schema_fields_MAX_GUARDRAIL_REGRESSION_BPS => $this->bounded($input, 'max_guardrail_regression_bps', (int)$current['max_guardrail_regression_bps'], 0, 10000),
            SeoOptimizationPolicy::schema_fields_CONTENT_BASELINE_DAYS => $this->bounded($input, 'content_baseline_days', (int)$current['content_baseline_days'], 1, 365),
            SeoOptimizationPolicy::schema_fields_SEO_BASELINE_DAYS => $this->bounded($input, 'seo_baseline_days', (int)$current['seo_baseline_days'], 1, 365),
            SeoOptimizationPolicy::schema_fields_EVALUATION_MIN_DAYS => $this->bounded($input, 'evaluation_min_days', (int)$current['evaluation_min_days'], 1, 90),
            SeoOptimizationPolicy::schema_fields_EVALUATION_MAX_DAYS => $this->bounded($input, 'evaluation_max_days', (int)$current['evaluation_max_days'], 1, 180),
            SeoOptimizationPolicy::schema_fields_COOLDOWN_DAYS => $this->bounded($input, 'cooldown_days', (int)$current['cooldown_days'], 0, 365),
        ];
        if ((int)$data[SeoOptimizationPolicy::schema_fields_EVALUATION_MAX_DAYS] < (int)$data[SeoOptimizationPolicy::schema_fields_EVALUATION_MIN_DAYS]) {
            throw new \InvalidArgumentException('evaluation_max_days must be at least evaluation_min_days.');
        }
        $model->setData($data)->save(true);
        return $this->get($websiteId);
    }

    /** @return list<array<string,mixed>> */
    public function persistedPolicies(): array
    {
        $rows = (clone $this->policyModel)->clearData()->clearQuery()->select()->fetchArray();
        return \is_array($rows) ? \array_values(\array_filter($rows, 'is_array')) : [];
    }

    /** @return array<string,mixed> */
    private function defaults(int $websiteId): array
    {
        return [
            'website_id' => $websiteId,
            'mode' => SeoOptimizationPolicy::MODE_SHADOW,
            'standing_authorized' => 0,
            'min_page_views' => 500,
            'min_conversions' => 30,
            'min_search_impressions' => 1000,
            'min_confidence' => 0.80,
            'min_uplift_bps' => 500,
            'max_guardrail_regression_bps' => 300,
            'content_baseline_days' => 14,
            'seo_baseline_days' => 28,
            'evaluation_min_days' => 7,
            'evaluation_max_days' => 28,
            'cooldown_days' => 14,
        ];
    }

    /** @param array<string,mixed> $data */
    private function bounded(array $data, string $key, int $default, int $min, int $max): int
    {
        $value = \is_numeric($data[$key] ?? null) ? (int)$data[$key] : $default;
        return \max($min, \min($max, $value));
    }

    private function flag(mixed $value): bool
    {
        return $value === true || \in_array(\strtolower((string)$value), ['1', 'true', 'yes', 'on'], true);
    }
}
