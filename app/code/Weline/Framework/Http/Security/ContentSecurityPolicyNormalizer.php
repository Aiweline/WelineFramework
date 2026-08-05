<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Security;

/**
 * CSP 规范化与「只收紧」判定（TASK-P1D-001 / TEST-P1D-01）。
 *
 * 子策略相对 Global 基线：同名指令的 source 集合必须为子集；不得删除基线指令；
 * 可新增更严指令。空基线不锁。
 */
final class ContentSecurityPolicyNormalizer
{
    public const ERROR_WEAKER = 'security_policy_weaker_than_baseline';

    /**
     * @return array<string, list<string>> directive => sorted unique sources
     */
    public function parse(string $policy): array
    {
        $policy = \trim($policy);
        if ($policy === '') {
            return [];
        }
        $out = [];
        foreach (\explode(';', $policy) as $chunk) {
            $chunk = \trim($chunk);
            if ($chunk === '') {
                continue;
            }
            $parts = \preg_split('/\s+/', $chunk) ?: [];
            $name = \strtolower((string)\array_shift($parts));
            if ($name === '') {
                continue;
            }
            $sources = [];
            foreach ($parts as $src) {
                $src = \trim((string)$src);
                if ($src !== '') {
                    $sources[] = $src;
                }
            }
            $sources = \array_values(\array_unique($sources));
            \sort($sources, \SORT_STRING);
            $out[$name] = $sources;
        }
        \ksort($out);

        return $out;
    }

    /**
     * @param array<string, list<string>> $directives
     */
    public function stringify(array $directives): string
    {
        \ksort($directives);
        $chunks = [];
        foreach ($directives as $name => $sources) {
            $sources = \array_values(\array_unique($sources));
            \sort($sources, \SORT_STRING);
            $chunks[] = $sources === [] ? (string)$name : (string)$name . ' ' . \implode(' ', $sources);
        }

        return \implode('; ', $chunks);
    }

    public function canonicalize(string $policy): string
    {
        return $this->stringify($this->parse($policy));
    }

    /**
     * candidate 是否弱于 baseline（允许了基线禁止的能力）。
     */
    public function isWeaker(string $candidate, string $baseline): bool
    {
        $base = $this->parse($baseline);
        if ($base === []) {
            return false;
        }
        $cand = $this->parse($candidate);
        foreach ($base as $directive => $baseSources) {
            if (!\array_key_exists($directive, $cand)) {
                return true;
            }
            if (!$this->isSourceSubset($cand[$directive], $baseSources)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 生效策略 = baseline ∩ child（child 空则仅 baseline）。
     */
    public function intersect(string $baseline, string $child): string
    {
        $base = $this->parse($baseline);
        $extra = $this->parse($child);
        if ($base === []) {
            return $this->stringify($extra);
        }
        if ($extra === []) {
            return $this->stringify($base);
        }
        $out = [];
        foreach ($base as $directive => $baseSources) {
            if (!\array_key_exists($directive, $extra)) {
                $out[$directive] = $baseSources;
                continue;
            }
            $inter = \array_values(\array_intersect($baseSources, $extra[$directive]));
            if ($inter === [] && $baseSources !== []) {
                $out[$directive] = ["'none'"];
            } else {
                \sort($inter, \SORT_STRING);
                $out[$directive] = $inter;
            }
        }
        foreach ($extra as $directive => $sources) {
            if (!\array_key_exists($directive, $out)) {
                $out[$directive] = $sources;
            }
        }

        return $this->stringify($out);
    }

    public function assertNotWeaker(string $candidate, string $baseline): void
    {
        if ($this->isWeaker($candidate, $baseline)) {
            throw new \InvalidArgumentException(self::ERROR_WEAKER);
        }
    }

    /**
     * @param list<string> $candidate
     * @param list<string> $baseline
     */
    private function isSourceSubset(array $candidate, array $baseline): bool
    {
        if ($baseline === []) {
            return $candidate === [];
        }
        if (\in_array("'none'", $candidate, true) && \count($candidate) === 1) {
            return true;
        }
        $baseSet = \array_fill_keys($baseline, true);
        foreach ($candidate as $src) {
            if (!isset($baseSet[$src])) {
                return false;
            }
        }

        return true;
    }
}
