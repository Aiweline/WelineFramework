<?php

declare(strict_types=1);

namespace Weline\Websites\Service\AI\Tool;

use Weline\Ai\Api\ToolInterface;

/**
 * AI 推荐域名工具
 *
 * 根据建站描述生成域名候选列表。
 * 返回的域名尚未检查可用性，需要调用 check_domain_availability 进一步验证。
 */
class RecommendDomainsTool implements ToolInterface
{
    private const DEFAULT_TLDS = ['.com', '.io', '.net', '.org'];

    public function getName(): string
    {
        return 'recommend_domains';
    }

    public function getDescription(): string
    {
        return 'Recommend domain name candidates based on a site description. Returns a list of suggested domains grouped by TLD, with reasoning for each suggestion.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'site_description' => [
                    'type' => 'string',
                    'description' => 'Site description or business name (e.g. "online pet store selling organic treats")',
                ],
                'desired_tlds' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Preferred TLDs to include (e.g. [".com", ".io"]). Defaults to [".com", ".io", ".net", ".org"]',
                ],
                'max_per_tld' => [
                    'type' => 'integer',
                    'description' => 'Maximum suggestions per TLD. Defaults to 3.',
                ],
            ],
            'required' => ['site_description'],
        ];
    }

    public function execute(array $args): mixed
    {
        $description = \trim((string)($args['site_description'] ?? ''));
        if ($description === '') {
            return ['error' => 'site_description is required'];
        }

        $desiredTlds = $args['desired_tlds'] ?? self::DEFAULT_TLDS;
        if (!\is_array($desiredTlds) || $desiredTlds === []) {
            $desiredTlds = self::DEFAULT_TLDS;
        }
        $desiredTlds = \array_slice($desiredTlds, 0, 5);

        $maxPerTld = (int)($args['max_per_tld'] ?? 3);
        if ($maxPerTld <= 0) {
            $maxPerTld = 3;
        }

        $keywords = $this->extractKeywords($description);
        $suggestions = $this->generateSuggestions($keywords, $desiredTlds, $maxPerTld);

        return [
            'site_description' => $description,
            'keywords' => $keywords,
            'suggestions' => $suggestions,
            'total_count' => \count($suggestions),
            'next_step' => 'check_domain_availability',
        ];
    }

    public function isEnabled(): bool
    {
        return true;
    }

    /**
     * Extract meaningful keywords from site description.
     *
     * @return list<string>
     */
    private function extractKeywords(string $description): array
    {
        $normalized = \mb_strtolower($description, 'UTF-8');
        $normalized = \preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $normalized);
        $normalized = \preg_replace('/\s+/', ' ', $normalized);
        $words = \array_filter(\explode(' ', $normalized), static fn(string $w): bool => \mb_strlen($w, 'UTF-8') >= 2);

        $stopWords = [
            'the', 'and', 'for', 'are', 'but', 'not', 'you', 'all', 'can', 'her',
            'was', 'one', 'our', 'out', 'day', 'get', 'has', 'him', 'his', 'how',
            'its', 'may', 'new', 'now', 'old', 'see', 'two', 'who', 'boy', 'did',
            'big', 'top', 'best', 'with', 'from', 'this', 'that', 'your', 'what',
            'about', 'which', 'their', 'there', 'when', 'where', 'would', 'could',
            'online', 'website', 'site', 'www', 'com', 'net', 'org', 'io',
        ];

        $keywords = [];
        foreach ($words as $word) {
            $word = \trim($word);
            if ($word === '' || \in_array($word, $stopWords, true)) {
                continue;
            }
            $keywords[] = $word;
        }

        return \array_values(\array_unique($keywords));
    }

    /**
     * Generate domain name suggestions based on keywords.
     *
     * @param list<string> $keywords
     * @param list<string> $tlds
     * @param int $maxPerTld
     * @return list<array{domain: string, reason: string, tld: string, pattern: string}>
     */
    private function generateSuggestions(array $keywords, array $tlds, int $maxPerTld): array
    {
        $suggestions = [];
        $usedDomains = [];

        foreach ($tlds as $tld) {
            $tld = \trim($tld);
            if (!\str_starts_with($tld, '.')) {
                $tld = '.' . $tld;
            }
            $addedForTld = 0;

            foreach ($this->buildPatterns($keywords) as $pattern) {
                if ($addedForTld >= $maxPerTld) {
                    break;
                }

                $domain = $pattern . $tld;
                $domain = \strtolower($domain);

                if (isset($usedDomains[$domain])) {
                    continue;
                }
                $usedDomains[$domain] = true;

                $suggestions[] = [
                    'domain' => $domain,
                    'reason' => $this->explainSuggestion($pattern, $tld),
                    'tld' => $tld,
                    'pattern' => $pattern,
                ];
                $addedForTld++;
            }

            if ($addedForTld < $maxPerTld) {
                $fallback = $this->generateFallback($keywords, $tld, $maxPerTld - $addedForTld);
                foreach ($fallback as $fb) {
                    if (isset($usedDomains[$fb['domain']])) {
                        continue;
                    }
                    $usedDomains[$fb['domain']] = true;
                    $suggestions[] = $fb;
                }
            }
        }

        return $suggestions;
    }

    /**
     * Build domain name patterns from keywords.
     *
     * @return list<string>
     */
    private function buildPatterns(array $keywords): array
    {
        $patterns = [];
        $k = \count($keywords);

        if ($k >= 1) {
            $main = $keywords[0];
            if (\mb_strlen($main, 'UTF-8') >= 3) {
                $patterns[] = $main;
            }
        }

        if ($k >= 2) {
            $second = $keywords[1];
            $patterns[] = $keywords[0] . $second;
            $patterns[] = $keywords[0] . '-' . $second;
        }

        if ($k >= 3) {
            $third = $keywords[2];
            $patterns[] = $keywords[0] . $keywords[1] . $third;
            $patterns[] = $keywords[0] . '-' . $keywords[1] . '-' . $third;
        }

        if ($k >= 2) {
            $patterns[] = $keywords[0] . 'shop';
            $patterns[] = $keywords[0] . 'store';
            $patterns[] = $keywords[0] . 'hub';
            $patterns[] = 'my' . $keywords[0];
            $patterns[] = 'get' . $keywords[0];
            $patterns[] = 'go' . $keywords[0];
        }

        if ($k >= 1) {
            $patterns[] = $keywords[0] . 'online';
            $patterns[] = 'the' . $keywords[0];
        }

        $filtered = [];
        foreach ($patterns as $p) {
            $clean = \preg_replace('/[^a-z0-9-]/', '', \mb_strtolower($p, 'UTF-8'));
            if ($clean !== '' && \mb_strlen($clean, 'UTF-8') >= 3) {
                $filtered[] = $clean;
            }
        }

        return \array_values(\array_unique($filtered));
    }

    /**
     * Generate fallback suggestions when keyword patterns are exhausted.
     *
     * @return list<array{domain: string, reason: string, tld: string, pattern: string}>
     */
    private function generateFallback(array $keywords, string $tld, int $count): array
    {
        $suggestions = [];
        $base = $keywords[0] ?? 'mysite';

        $fallbackPatterns = [
            [$base . 'app', 'App-focused brandable name'],
            [$base . 'co', 'Short brandable alternative'],
            ['try' . $base, 'Action-oriented suggestion'],
            [$base . 'pro', 'Professional services variant'],
            [$base . 'site', 'Simple and direct'],
        ];

        foreach ($fallbackPatterns as [$pattern, $reason]) {
            if (\count($suggestions) >= $count) {
                break;
            }
            $domain = \strtolower(\preg_replace('/[^a-z0-9-]/', '', $pattern)) . $tld;
            $suggestions[] = [
                'domain' => $domain,
                'reason' => $reason,
                'tld' => $tld,
                'pattern' => $pattern,
            ];
        }

        return $suggestions;
    }

    private function explainSuggestion(string $pattern, string $tld): string
    {
        $hasHyphen = \str_contains($pattern, '-');
        $isShort = \mb_strlen($pattern, 'UTF-8') <= 6;

        if ($hasHyphen) {
            return __('Combination of keywords with hyphen for readability');
        }
        if ($isShort) {
            return __('Short brandable name, easy to type and remember');
        }

        return __('Direct keyword-based domain, clear and descriptive');
    }
}
