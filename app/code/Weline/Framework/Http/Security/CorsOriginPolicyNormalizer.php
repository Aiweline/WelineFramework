<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Security;

/**
 * CORS Origin 白名单：子列表只能是父列表子集（TASK-P1D-001）。
 */
final class CorsOriginPolicyNormalizer
{
    public const ERROR_WEAKER = 'security_policy_weaker_than_baseline';

    /**
     * @return list<string>
     */
    public function parse(string $raw): array
    {
        $raw = \trim($raw);
        if ($raw === '') {
            return [];
        }
        if ($raw === '*') {
            return ['*'];
        }
        $parts = \preg_split('/[\s,]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = \trim((string)$p);
            if ($p !== '') {
                $out[] = \rtrim($p, '/');
            }
        }
        $out = \array_values(\array_unique($out));
        \sort($out, \SORT_STRING);

        return $out;
    }

    /**
     * @param list<string> $origins
     */
    public function stringify(array $origins): string
    {
        $origins = \array_values(\array_unique($origins));
        \sort($origins, \SORT_STRING);

        return \implode(' ', $origins);
    }

    public function isWeaker(string $candidate, string $baseline): bool
    {
        $base = $this->parse($baseline);
        if ($base === [] || $base === ['*']) {
            return false;
        }
        $cand = $this->parse($candidate);
        if ($cand === ['*']) {
            return true;
        }
        $baseSet = \array_fill_keys($base, true);
        foreach ($cand as $origin) {
            if (!isset($baseSet[$origin])) {
                return true;
            }
        }

        return false;
    }

    public function intersect(string $baseline, string $child): string
    {
        $base = $this->parse($baseline);
        $extra = $this->parse($child);
        if ($base === [] || $base === ['*']) {
            return $this->stringify($extra);
        }
        if ($extra === [] || $extra === ['*']) {
            return $this->stringify($base === ['*'] ? $extra : $base);
        }
        $inter = \array_values(\array_intersect($base, $extra));
        \sort($inter, \SORT_STRING);

        return $this->stringify($inter);
    }

    public function assertNotWeaker(string $candidate, string $baseline): void
    {
        if ($this->isWeaker($candidate, $baseline)) {
            throw new \InvalidArgumentException(self::ERROR_WEAKER);
        }
    }
}
