<?php

declare(strict_types=1);

/**
 * 范围越界 Guard
 *
 * 通用规则：
 * - URI 正则匹配（默认匹配所有）
 * - 单一数字参数越界检查（min/max）
 * - 字符串参数白名单 / 长度限制
 *
 * 配置示例：
 * ```
 * new BoundedUrlGuard('product_id_max', [
 *     'pattern' => '#^/(?:[a-z]{2}_[A-Z]{2}/)?product/(?<id>\d+)#',
 *     'param_source' => 'pattern',   // pattern | get | both
 *     'param_name' => 'id',
 *     'min' => 1,
 *     'max' => 1000000,
 *     'reject_status' => 410,
 * ]);
 * ```
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Router\UrlGuard;

class BoundedUrlGuard implements UrlGuardInterface
{
    /** @var array<string, mixed> */
    private array $cachedMatches = [];

    /**
     * @param array{
     *     pattern?: string,
     *     param_source?: 'pattern'|'get'|'both',
     *     param_name?: string,
     *     min?: int|null,
     *     max?: int|null,
     *     allow?: array<int, string>,
     *     max_length?: int|null,
     *     reject_status?: int,
     *     reject_reason?: string
     * } $config
     */
    public function __construct(private string $name, private array $config = [])
    {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function matches(string $uri, array $params, array $headers = []): bool
    {
        $pattern = $this->config['pattern'] ?? null;
        if (!\is_string($pattern) || $pattern === '') {
            return true;
        }

        $matches = [];
        if (\preg_match($pattern, $uri, $matches) !== 1) {
            $this->cachedMatches[$uri] = null;
            return false;
        }

        $this->cachedMatches[$uri] = $matches;
        return true;
    }

    public function evaluate(string $uri, array $params, array $headers = []): GuardDecision
    {
        $value = $this->resolveValue($uri, $params);

        if ($value === null) {
            return GuardDecision::skip($this->name, 'parameter_not_present');
        }

        $rejectStatus = (int)($this->config['reject_status'] ?? 410);
        $reason = (string)($this->config['reject_reason'] ?? 'url_out_of_bounds');

        if (\is_numeric($value)) {
            $numeric = (int)$value;
            $min = $this->config['min'] ?? null;
            $max = $this->config['max'] ?? null;

            if ($min !== null && $numeric < (int)$min) {
                return GuardDecision::reject($this->name, $reason, $rejectStatus, [
                    'param' => $this->config['param_name'] ?? '',
                    'value' => $numeric,
                    'min' => (int)$min,
                ]);
            }
            if ($max !== null && $numeric > (int)$max) {
                return GuardDecision::reject($this->name, $reason, $rejectStatus, [
                    'param' => $this->config['param_name'] ?? '',
                    'value' => $numeric,
                    'max' => (int)$max,
                ]);
            }
        } else {
            $string = (string)$value;
            $allow = $this->config['allow'] ?? null;
            if (\is_array($allow) && $allow !== [] && !\in_array($string, $allow, true)) {
                return GuardDecision::reject($this->name, $reason, $rejectStatus, [
                    'param' => $this->config['param_name'] ?? '',
                    'value' => $string,
                    'allowed' => $allow,
                ]);
            }

            $maxLength = $this->config['max_length'] ?? null;
            if ($maxLength !== null && \strlen($string) > (int)$maxLength) {
                return GuardDecision::reject($this->name, $reason, $rejectStatus, [
                    'param' => $this->config['param_name'] ?? '',
                    'length' => \strlen($string),
                    'max_length' => (int)$maxLength,
                ]);
            }
        }

        return GuardDecision::pass($this->name);
    }

    private function resolveValue(string $uri, array $params): mixed
    {
        $paramName = $this->config['param_name'] ?? null;
        if (!\is_string($paramName) || $paramName === '') {
            return null;
        }

        $source = $this->config['param_source'] ?? 'both';

        if ($source === 'pattern' || $source === 'both') {
            $matches = $this->cachedMatches[$uri] ?? null;
            if (\is_array($matches) && \array_key_exists($paramName, $matches)) {
                return $matches[$paramName];
            }
        }

        if ($source === 'get' || $source === 'both') {
            if (\array_key_exists($paramName, $params)) {
                return $params[$paramName];
            }
        }

        return null;
    }
}
