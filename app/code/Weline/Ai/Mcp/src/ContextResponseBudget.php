<?php

declare(strict_types=1);

namespace LearningMcp;

/** Budget guidance at its serialized boundary; editable regions are never shortened. */
final class ContextResponseBudget
{
    public static function estimate(array $value): int
    {
        return (int) ceil(mb_strlen(Json::encode($value), 'UTF-8') / 4);
    }

    /** @param callable(array):array|null $envelope */
    public static function fit(array $guidance, int $budget, ?callable $envelope = null): array
    {
        $measure = $envelope ?? static fn (array $value): array => $value;
        $guidance['token_usage'] = [
            'budget' => $budget,
            'estimated' => $budget,
            'estimate_method' => 'serialized_unicode_characters_div_4',
            'scope' => $envelope === null ? 'guidance_body' : 'tools_call_result',
            'omitted_fragments' => (int) ($guidance['token_usage']['omitted_fragments'] ?? 0),
            'budget_exceeded' => false,
        ];
        while (self::estimate($measure($guidance)) > $budget) {
            $fragments = (array) ($guidance['fragments'] ?? []);
            if (count($fragments) > 1) {
                array_pop($fragments);
                $guidance['fragments'] = $fragments;
                ++$guidance['token_usage']['omitted_fragments'];
                continue;
            }
            if ($fragments !== []) {
                $fragment = $fragments[0];
                $content = (string) ($fragment['content'] ?? '');
                $overflowCharacters = (self::estimate($measure($guidance)) - $budget) * 4;
                $length = max(0, mb_strlen($content, 'UTF-8') - $overflowCharacters - 128);
                if ($length >= 64) {
                    $content = mb_substr($content, 0, $length, 'UTF-8');
                    $newline = mb_strrpos($content, "\n", 0, 'UTF-8');
                    if ($newline !== false && $newline >= 64) {
                        $content = mb_substr($content, 0, $newline, 'UTF-8');
                    }
                    $fragment['content'] = $content;
                    $fragment['content_truncated'] = true;
                    $fragment['end_line'] = (int) ($fragment['start_line'] ?? 1) + substr_count($content, "\n");
                    $fragment['token_estimate'] = (int) ceil(mb_strlen($content, 'UTF-8') / 4);
                    $guidance['fragments'] = [$fragment];
                    continue;
                }
                $guidance['fragments'] = [];
                ++$guidance['token_usage']['omitted_fragments'];
                continue;
            }
            // Required routing, constraints and session state cannot be silently removed.
            // Report their true cost when a request is smaller than this fixed overhead.
            $guidance['token_usage']['budget_exceeded'] = true;
            break;
        }
        if (isset($guidance['query'])) {
            $guidance['query']['result_count'] = count((array) ($guidance['fragments'] ?? []));
        }
        // Including the estimate changes the serialized size by a few digits.
        for ($pass = 0; $pass < 8; ++$pass) {
            $estimated = self::estimate($measure($guidance));
            if ($estimated === $guidance['token_usage']['estimated']) {
                break;
            }
            $guidance['token_usage']['estimated'] = $estimated;
        }
        $guidance['token_usage']['budget_exceeded'] = $guidance['token_usage']['estimated'] > $budget;
        // false/true differs by one character; keep the reported estimate exact.
        $guidance['token_usage']['estimated'] = self::estimate($measure($guidance));
        return $guidance;
    }
}
