<?php

declare(strict_types=1);

namespace Weline\Cdn\Service;

use Weline\Cdn\Model\ApiRule;
use Weline\Framework\Service\Query\BinQueryCachePolicy;

/**
 * 判定 ApiRule 属于保真车道还是 path-packable。
 *
 * 优先显式 lane 字段；否则按 expression/action 结构识别（MARKER_PARAM / method+query / custom cache_key）。
 * 禁止业务路径表。
 */
final class CdnRuleLaneClassifier
{
    /**
     * @param ApiRule|array{
     *   lane?:string,
     *   expression?:string,
     *   action?:array<string,mixed>|string,
     *   route?:string
     * } $rule
     */
    public function laneOf(ApiRule|array $rule): string
    {
        if ($this->isPredicateFidelity($rule)) {
            return CdnRuleLane::PREDICATE_FIDELITY;
        }

        return CdnRuleLane::PATH_PACKABLE;
    }

    /**
     * @param ApiRule|array{
     *   lane?:string,
     *   expression?:string,
     *   action?:array<string,mixed>|string,
     *   route?:string
     * } $rule
     */
    public function isPredicateFidelity(ApiRule|array $rule): bool
    {
        // Explicit fidelity always wins. Structural signals also win over a default
        // path_packable column (legacy rows after schema add) so marker rules never
        // fall into path-OR pack. Explicit path_packable cannot override marker shape.
        if ($this->explicitLane($rule) === CdnRuleLane::PREDICATE_FIDELITY) {
            return true;
        }

        $expression = $this->expressionOf($rule);
        $action = $this->actionOf($rule);
        $marker = BinQueryCachePolicy::MARKER_PARAM;

        if ($expression !== '' && \str_contains($expression, $marker)) {
            return true;
        }

        if ($this->actionHasMarkerCacheKey($action, $marker)) {
            return true;
        }

        if (
            $expression !== ''
            && \preg_match('/http\.request\.method\s+eq\s+"POST"/i', $expression) === 1
            && \preg_match('/http\.request\.uri\.query\s+contains\s+"/i', $expression) === 1
        ) {
            return true;
        }

        return false;
    }

    /**
     * @param ApiRule|array{lane?:string} $rule
     */
    private function explicitLane(ApiRule|array $rule): ?string
    {
        if ($rule instanceof ApiRule) {
            $raw = $rule->getData(ApiRule::schema_fields_LANE);
            if ($raw === null || \trim((string)$raw) === '') {
                return null;
            }

            return CdnRuleLane::normalize((string)$raw);
        }

        if (!isset($rule['lane']) || \trim((string)$rule['lane']) === '') {
            return null;
        }

        return CdnRuleLane::normalize((string)$rule['lane']);
    }

    /**
     * @param ApiRule|array{expression?:string} $rule
     */
    private function expressionOf(ApiRule|array $rule): string
    {
        if ($rule instanceof ApiRule) {
            return \trim((string)$rule->getData(ApiRule::schema_fields_EXPRESSION));
        }

        return \trim((string)($rule['expression'] ?? ''));
    }

    /**
     * @param ApiRule|array{action?:array<string,mixed>|string} $rule
     * @return array<string, mixed>
     */
    private function actionOf(ApiRule|array $rule): array
    {
        if ($rule instanceof ApiRule) {
            return $rule->getActionArray();
        }
        $action = $rule['action'] ?? [];
        if (\is_string($action)) {
            $decoded = \json_decode($action, true);
            $action = \is_array($decoded) ? $decoded : [];
        }

        return \is_array($action) ? $action : [];
    }

    /**
     * @param array<string, mixed> $action
     */
    private function actionHasMarkerCacheKey(array $action, string $marker): bool
    {
        $cache = $action['cache'] ?? null;
        if (!\is_array($cache)) {
            return false;
        }
        $cacheKey = $cache['cache_key'] ?? null;
        if (!\is_array($cacheKey)) {
            return false;
        }
        $custom = $cacheKey['custom_key'] ?? null;
        if (!\is_array($custom)) {
            return false;
        }
        $queryString = $custom['query_string'] ?? null;
        if (!\is_array($queryString)) {
            return false;
        }
        $include = $queryString['include'] ?? null;
        if (!\is_array($include)) {
            return false;
        }
        foreach ($include as $item) {
            if ((string)$item === $marker) {
                return true;
            }
        }

        return false;
    }
}
