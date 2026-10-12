<?php

declare(strict_types=1);

namespace Weline\Cdn\Service;

use Weline\Cdn\Model\ApiRule;
use Weline\Framework\Service\Query\BinQueryCachePolicy;

/**
 * 将保真车道多条 inventory 细规则压成 ≤1 条边缘规则（POST + marker + cache_key）。
 * 禁止降级为裸 starts_with(path)。
 */
final class BinQueryFidelityEdgeCompressor
{
    private readonly CdnRuleLaneClassifier $classifier;

    public function __construct(?CdnRuleLaneClassifier $classifier = null)
    {
        $this->classifier = $classifier ?? new CdnRuleLaneClassifier();
    }

    /**
     * @param list<ApiRule|array<string, mixed>> $apiRules
     * @return list<array<string, mixed>> 0 或 1 条 CF 规则（含 _weline_identity）
     */
    public function compress(array $apiRules): array
    {
        $fidelity = [];
        foreach ($apiRules as $rule) {
            if ($this->classifier->isPredicateFidelity($rule)) {
                $fidelity[] = $rule;
            }
        }
        if ($fidelity === []) {
            return [];
        }

        $paths = [];
        $ttls = [];
        $statusCodes = [];
        foreach ($fidelity as $rule) {
            foreach ($this->extractExactPaths($rule) as $path) {
                $paths[$path] = $path;
            }
            $ttl = $this->extractTtl($rule);
            if ($ttl !== null && $ttl > 0) {
                $ttls[] = $ttl;
            }
            foreach ($this->extractStatusCodes($rule) as $code) {
                $statusCodes[$code] = $code;
            }
        }

        if ($paths === [] || $ttls === []) {
            if (\function_exists('w_log_warning')) {
                w_log_warning(
                    'CDN BinQueryFidelityEdgeCompressor: fidelity rules present but no safe edge expression; omitting lane',
                    ['count' => \count($fidelity)],
                    'cdn'
                );
            }

            return [];
        }

        $marker = BinQueryCachePolicy::MARKER_PARAM;
        $pathParts = [];
        foreach (\array_values($paths) as $path) {
            $escaped = \str_replace(['\\', '"'], ['\\\\', '\\"'], $path);
            $pathParts[] = 'http.request.uri.path eq "' . $escaped . '"';
        }
        $pathExpr = \count($pathParts) === 1
            ? $pathParts[0]
            : '(' . \implode(' or ', $pathParts) . ')';
        $expression = $pathExpr
            . ' and http.request.method eq "POST"'
            . ' and http.request.uri.query contains "' . $marker . '="';

        $ttl = $this->medianInt($ttls);
        $codes = $statusCodes !== [] ? \array_values($statusCodes) : [200];
        \sort($codes);

        // Free Cache Rules 拒收 custom cache_key（HTTP 400）；边缘保真靠 expression 的
        // POST + __wq_cache= 谓词。inventory 细规则仍可带 cache_key，planned 边缘不加。
        return [[
            'expression' => $expression,
            'action' => [
                'cache' => [
                    'status_code' => $codes,
                    'ttl' => $ttl,
                    'mode' => 'override_origin',
                ],
            ],
            'description' => 'Weline fidelity:binquery ttl=' . $ttl . ' n=' . \count($fidelity),
            'enabled' => true,
            '_weline_identity' => 'fidelity:binquery:0',
        ]];
    }

    /**
     * @param ApiRule|array<string, mixed> $rule
     * @return list<string>
     */
    private function extractExactPaths(ApiRule|array $rule): array
    {
        $expression = $rule instanceof ApiRule
            ? \trim((string)$rule->getData(ApiRule::schema_fields_EXPRESSION))
            : \trim((string)($rule['expression'] ?? ''));
        $paths = [];
        if (
            $expression !== ''
            && \preg_match_all('/http\.request\.uri\.path\s+eq\s+"([^"]+)"/i', $expression, $matches)
        ) {
            foreach ($matches[1] as $path) {
                $path = (string)$path;
                if ($path !== '' && \str_starts_with($path, '/')) {
                    $paths[$path] = $path;
                }
            }
        }

        // Do NOT fall back to route/starts_with — that recreates the dangerous path pack.
        return \array_values($paths);
    }

    /**
     * @param ApiRule|array<string, mixed> $rule
     */
    private function extractTtl(ApiRule|array $rule): ?int
    {
        $action = $rule instanceof ApiRule
            ? $rule->getActionArray()
            : (\is_array($rule['action'] ?? null) ? $rule['action'] : []);
        $cache = $action['cache'] ?? null;
        if (!\is_array($cache) || !isset($cache['ttl'])) {
            return null;
        }

        return (int)$cache['ttl'];
    }

    /**
     * @param ApiRule|array<string, mixed> $rule
     * @return list<int>
     */
    private function extractStatusCodes(ApiRule|array $rule): array
    {
        $action = $rule instanceof ApiRule
            ? $rule->getActionArray()
            : (\is_array($rule['action'] ?? null) ? $rule['action'] : []);
        $cache = $action['cache'] ?? null;
        if (!\is_array($cache)) {
            return [200];
        }
        $codes = $cache['status_code'] ?? [200];
        if (!\is_array($codes)) {
            return [200];
        }
        $out = [];
        foreach ($codes as $code) {
            $out[] = (int)$code;
        }

        return $out !== [] ? $out : [200];
    }

    /**
     * @param list<int> $values
     */
    private function medianInt(array $values): int
    {
        if ($values === []) {
            return 0;
        }
        \sort($values);
        $count = \count($values);
        $mid = (int)\floor(($count - 1) / 2);
        if ($count % 2 === 1) {
            return $values[$mid];
        }

        return (int)\round(($values[$mid] + $values[$mid + 1]) / 2);
    }
}
