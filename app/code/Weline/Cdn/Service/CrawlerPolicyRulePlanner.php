<?php

declare(strict_types=1);

namespace Weline\Cdn\Service;

use Weline\Server\Security\CrawlerBlockCatalog;
use Weline\Websites\Api\Crawler\CrawlerPolicyEntry;

/**
 * Pure planner: Website crawler policy entries -> Cloudflare WAF custom rules.
 *
 * Free plan: only `contains` (no `matches`), and all block tokens are grouped
 * into one rule unless the expression exceeds the safe length.
 */
final class CrawlerPolicyRulePlanner
{
    public const DESCRIPTION_PREFIX = 'weline-crawler:';
    public const MAX_EXPRESSION_LENGTH = 3900;

    /**
     * @param list<CrawlerPolicyEntry> $entries
     * @return array{
     *     rules:list<array{description:string,expression:string,action:string,enabled:bool}>,
     *     hash:string,
     *     block_tokens:list<string>,
     *     skipped_entries:list<string>
     * }
     */
    public function plan(array $entries, int $websiteId = 0): array
    {
        $blockTokens = [];
        $allowTokens = [];
        $skipped = [];

        // WLS hard floor: friendly search/social/AI allowlist always passes,
        // so the edge must not block them even when a stale site row says block.
        if (\class_exists(CrawlerBlockCatalog::class)) {
            foreach (CrawlerBlockCatalog::allowlistEntries() as $item) {
                $tokens = CrawlerBlockCatalog::tokensFor((string)($item['id'] ?? ''))['ua_tokens'];
                foreach ($tokens as $token) {
                    $allowTokens[\strtolower($token)] = $token;
                }
            }
        }

        foreach ($entries as $entry) {
            if (!$entry instanceof CrawlerPolicyEntry || !$entry->enabled) {
                continue;
            }
            $tokens = $this->usableTokens($entry->uaTokens);
            if ($entry->action === 'allow') {
                foreach ($tokens as $token) {
                    $allowTokens[\strtolower($token)] = $token;
                }
                continue;
            }
            if ($entry->action !== 'block') {
                continue;
            }
            if ($tokens === []) {
                $skipped[] = $entry->id;
                continue;
            }
            foreach ($tokens as $token) {
                $blockTokens[\strtolower($token)] = $token;
            }
        }

        // Allow wins over block for the same literal token.
        foreach (\array_keys($allowTokens) as $allowKey) {
            unset($blockTokens[$allowKey]);
        }

        if ($blockTokens === []) {
            return [
                'rules' => [],
                'hash' => \hash('sha256', ''),
                'block_tokens' => [],
                'skipped_entries' => $skipped,
            ];
        }

        \ksort($blockTokens);
        \ksort($allowTokens);

        $parts = [];
        foreach ($blockTokens as $token) {
            $conditions = ['lower(http.user_agent) contains "' . $this->escapeToken($token) . '"'];
            foreach ($allowTokens as $allowToken) {
                if (\strcasecmp($allowToken, $token) === 0) {
                    continue;
                }
                if (\stripos($allowToken, $token) !== false) {
                    $conditions[] = 'not lower(http.user_agent) contains "' . $this->escapeToken($allowToken) . '"';
                }
            }
            $parts[] = \count($conditions) === 1
                ? $conditions[0]
                : '(' . \implode(' and ', $conditions) . ')';
        }

        $rules = [];
        $chunks = $this->chunkParts($parts);
        foreach ($chunks as $index => $chunk) {
            $expression = \implode(' or ', $chunk);
            $hash = \substr(\hash('sha256', $expression), 0, 12);
            $suffix = \count($chunks) > 1 ? ':' . ($index + 1) : '';
            $rules[] = [
                'description' => self::DESCRIPTION_PREFIX . $websiteId . ':v1:block:' . $hash . $suffix,
                'expression' => $expression,
                'action' => 'block',
                'enabled' => true,
            ];
        }

        return [
            'rules' => $rules,
            'hash' => \substr(\hash('sha256', \implode("\n", \array_column($rules, 'expression'))), 0, 24),
            'block_tokens' => \array_values($blockTokens),
            'skipped_entries' => $skipped,
        ];
    }

    /**
     * @param list<string> $tokens
     * @return list<string>
     */
    private function usableTokens(array $tokens): array
    {
        $usable = [];
        foreach ($tokens as $token) {
            $token = \trim((string)$token);
            if ($token === '' || \strlen($token) > 128) {
                continue;
            }
            if (\preg_match('#^[A-Za-z0-9][A-Za-z0-9 ._+/-]*$#', $token) !== 1) {
                continue;
            }
            $usable[\strtolower($token)] = $token;
        }

        return \array_values($usable);
    }

    private function escapeToken(string $token): string
    {
        // Cloudflare `contains` is case-sensitive; lower() only lowercases the UA.
        return \strtolower(\str_replace(['\\', '"'], ['', ''], $token));
    }

    /**
     * @param list<string> $parts
     * @return list<list<string>>
     */
    private function chunkParts(array $parts): array
    {
        $chunks = [];
        $current = [];
        $length = 0;
        foreach ($parts as $part) {
            $addition = ($current === [] ? 0 : 4) + \strlen($part);
            if ($current !== [] && $length + $addition > self::MAX_EXPRESSION_LENGTH) {
                $chunks[] = $current;
                $current = [];
                $length = 0;
                $addition = \strlen($part);
            }
            $current[] = $part;
            $length += $addition;
        }
        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
