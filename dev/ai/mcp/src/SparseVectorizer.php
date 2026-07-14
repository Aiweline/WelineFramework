<?php

declare(strict_types=1);

namespace LearningMcp;

final class SparseVectorizer
{
    private int $dimensions;
    private int $maxTerms;

    public function __construct(Config $config)
    {
        $this->dimensions = max(128, (int) $config->get('index.vector_dimensions', 2_048));
        $this->maxTerms = max(8, (int) $config->get('index.vector_max_terms', 24));
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    /** @return list<string> */
    public function tokens(string $text): array
    {
        $tokens = [];
        $lower = mb_strtolower($text, 'UTF-8');
        if (preg_match_all('/[\p{L}\p{N}_\\\\:\.\/-]+/u', $text, $matches) === 1 || !empty($matches[0])) {
            foreach ($matches[0] as $original) {
                $raw = mb_strtolower($original, 'UTF-8');
                $raw = trim($raw, "_\\:./-");
                if ($raw === '') {
                    continue;
                }
                $tokens[] = 'raw:' . $raw;
                $expanded = preg_replace('/([a-z0-9])([A-Z])/u', '$1 $2', $original) ?? $original;
                foreach (preg_split('/[\s_\\\\:\.\/-]+/u', $expanded, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                    $part = mb_strtolower($part, 'UTF-8');
                    if (mb_strlen($part, 'UTF-8') >= 2 || preg_match('/^\d+$/', $part) === 1) {
                        $tokens[] = 'word:' . $part;
                    }
                }
            }
        }
        if (preg_match_all('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]+/u', $lower, $runs) === 1 || !empty($runs[0])) {
            foreach ($runs[0] as $run) {
                $length = mb_strlen($run, 'UTF-8');
                for ($index = 0; $index < $length; ++$index) {
                    $tokens[] = 'cjk1:' . mb_substr($run, $index, 1, 'UTF-8');
                    if ($index + 1 < $length) {
                        $tokens[] = 'cjk2:' . mb_substr($run, $index, 2, 'UTF-8');
                    }
                    if ($index + 2 < $length) {
                        $tokens[] = 'cjk3:' . mb_substr($run, $index, 3, 'UTF-8');
                    }
                }
            }
        }

        return $tokens;
    }

    /** @return array<int, float> Map of feature bucket to normalized signed weight. */
    public function vectorize(string $text): array
    {
        $frequencies = [];
        foreach ($this->tokens($text) as $token) {
            $frequencies[$token] = ($frequencies[$token] ?? 0) + 1;
        }
        if ($frequencies === []) {
            return [];
        }
        arsort($frequencies, SORT_NUMERIC);
        $frequencies = array_slice($frequencies, 0, $this->maxTerms, true);
        $vector = [];
        foreach ($frequencies as $token => $frequency) {
            $digest = hash('sha256', $token, true);
            $bucketBytes = unpack('Nbucket', substr($digest, 0, 4));
            $bucket = ((int) ($bucketBytes['bucket'] ?? 0)) % $this->dimensions;
            $sign = (ord($digest[4]) & 1) === 0 ? 1.0 : -1.0;
            $weight = (1.0 + log((float) $frequency)) * $sign;
            $vector[$bucket] = ($vector[$bucket] ?? 0.0) + $weight;
        }
        $norm = sqrt(array_sum(array_map(static fn (float $value): float => $value * $value, $vector)));
        if ($norm <= 0.0) {
            return [];
        }
        foreach ($vector as $bucket => $weight) {
            $vector[$bucket] = $weight / $norm;
        }
        ksort($vector, SORT_NUMERIC);

        return $vector;
    }

    /** @param array<int, float> $left
     *  @param array<int, float> $right
     */
    public function dot(array $left, array $right): float
    {
        if (count($left) > count($right)) {
            [$left, $right] = [$right, $left];
        }
        $score = 0.0;
        foreach ($left as $bucket => $weight) {
            $score += $weight * ($right[$bucket] ?? 0.0);
        }

        return max(-1.0, min(1.0, $score));
    }
}
