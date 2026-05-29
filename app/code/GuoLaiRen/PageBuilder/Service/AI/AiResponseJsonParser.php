<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * AI 响应 JSON 解析与修复
 *
 * 从 AI 原始响应文本中提取 JSON 并做常见修复（控制字符、尾逗号、截断），供 component-stream / agent 使用。
 */

namespace GuoLaiRen\PageBuilder\Service\AI;

class AiResponseJsonParser
{
    /**
     * 从 AI 响应中提取并解析 JSON，一步到位
     */
    public function extractAndDecode(string $response): ?array
    {
        $json = $this->extractJson($response);
        return $json !== null ? $this->decodeWithRepair($json) : null;
    }

    /**
     * 从 AI 响应中提取 JSON 字符串
     * 支持：```json...```、```...```、纯 JSON、多个代码块时选最像 JSON 的、或从任意 { 平衡取整段
     */
    public function extractJson(string $response): ?string
    {
        $response = trim($response);
        if ($response === '') {
            return null;
        }

        // 1a. 代码块：要求结束围栏在行首
        if (preg_match('/```(?:json)?\s*\r?\n([\s\S]*?)\r?\n\s*```/s', $response, $lineEndMatch)) {
            $candidate = trim($lineEndMatch[1]);
            if (str_starts_with($candidate, '{')) {
                $decoded = $this->decodeWithRepair($candidate);
                if ($decoded !== null && is_array($decoded)) {
                    return $candidate;
                }
                return $candidate;
            }
        }

        $hasCodeBlocks = (bool) preg_match_all('/```(?:json)?\s*([\s\S]*?)\s*```/s', $response, $cbMatches);
        $firstBrace = strpos($response, '{');

        if ($hasCodeBlocks) {
            $best = null;
            $bestLen = 0;
            foreach ($cbMatches[1] as $block) {
                $candidate = trim($block);
                if ($candidate === '' || !str_starts_with($candidate, '{')) {
                    continue;
                }
                $decoded = $this->decodeWithRepair($candidate);
                if ($decoded !== null && is_array($decoded)) {
                    return $candidate;
                }
                if (strlen($candidate) > $bestLen) {
                    $bestLen = strlen($candidate);
                    $best = $candidate;
                }
            }
            if ($best !== null) {
                $repaired = $this->repairTruncatedJson($this->fixControlCharsInJsonStrings($best));
                if ($repaired !== null) {
                    $decoded = json_decode($repaired, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        return $repaired;
                    }
                }
            }
        }

        if (str_starts_with($response, '{')) {
            if (str_ends_with($response, '}')) {
                $decoded = $this->decodeWithRepair($response);
                if ($decoded !== null && is_array($decoded)) {
                    return $response;
                }
            }
            $extracted = $this->extractBalancedBraces($response, 0);
            if ($extracted !== null) {
                return $extracted;
            }
            if (str_ends_with($response, '}')) {
                return $response;
            }
        }

        if ($firstBrace !== false) {
            $extracted = $this->extractBalancedBraces($response, $firstBrace);
            if ($extracted !== null) {
                return $extracted;
            }
            if ($firstBrace === 0) {
                return $response;
            }
        }

        $lines = preg_split('/\r?\n/', $response);
        $startIdx = 0;
        $endIdx = count($lines) - 1;
        while ($startIdx <= $endIdx && trim($lines[$startIdx]) !== '' && !str_contains(trim($lines[$startIdx]), '{')) {
            $startIdx++;
        }
        while ($endIdx >= $startIdx && trim($lines[$endIdx]) !== '' && !str_contains(trim($lines[$endIdx]), '}')) {
            $endIdx--;
        }
        if ($startIdx <= $endIdx) {
            $trimmed = implode("\n", array_slice($lines, $startIdx, $endIdx - $startIdx + 1));
            $pos = strpos($trimmed, '{');
            if ($pos !== false) {
                $extracted = $this->extractBalancedBraces($trimmed, $pos);
                if ($extracted !== null) {
                    return $extracted;
                }
            }
        }

        return null;
    }

    /**
     * 解析 JSON 并尝试修复常见问题
     * 首次解析前先做控制字符修复（字符串内真实换行→\n 等），避免 Syntax error；再尝试尾逗号等修复。
     */
    public function decodeWithRepair(string $json): ?array
    {
        // 第一次尝试：先修控制字符 + 尾逗号，再解析（不对原始 $json 直接 json_decode）
        $normalized = $this->fixControlCharsInJsonStrings($json);
        $normalized = $this->repairMissingColonAfterKnownKeys($normalized);
        $normalized = preg_replace('/,\s*}/', '}', $normalized);
        $normalized = preg_replace('/,\s*]/', ']', $normalized);
        $data = json_decode($normalized, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            return $data;
        }

        // 第二次：对原始 $json 再做一次相同修复后解析（兜底）
        $fixed = $this->fixControlCharsInJsonStrings($json);
        $fixed = $this->repairMissingColonAfterKnownKeys($fixed);
        $fixed = preg_replace('/,\s*}/', '}', $fixed);
        $fixed = preg_replace('/,\s*]/', ']', $fixed);
        $data = json_decode($fixed, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            return $data;
        }

        $fixed = preg_replace('/[\x00-\x1F\x7F]/u', '', $json);
        $fixed = $this->repairMissingColonAfterKnownKeys((string)$fixed);
        $fixed = preg_replace('/,\s*}/', '}', $fixed);
        $fixed = preg_replace('/,\s*]/', ']', $fixed);
        $data = json_decode($fixed, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            return $data;
        }

        $normalizedForRepair = $this->fixControlCharsInJsonStrings($json);
        $normalizedForRepair = $this->repairMissingColonAfterKnownKeys($normalizedForRepair);
        $repaired = $this->repairTruncatedJson($normalizedForRepair);
        if ($repaired !== null) {
            $data = json_decode($repaired, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                return $data;
            }
        }

        $repairedRaw = $this->repairTruncatedJson($json);
        if ($repairedRaw !== null && $repairedRaw !== $repaired) {
            $data = json_decode($repairedRaw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                return $data;
            }
        }

        return null;
    }

    private function repairMissingColonAfterKnownKeys(string $json): string
    {
        $knownKeys = [
            'extra_fields',
            'php_variables',
            'css_extra',
            'css_responsive',
            'html_content',
            'html_extra',
            'html_extra_column',
            'js_content',
            'markdown',
            'plan_json',
            'style_plan',
        ];
        $keyPattern = implode('|', array_map(static fn(string $key): string => preg_quote($key, '/'), $knownKeys));

        $json = preg_replace(
            '/(^|[{\[,]\s*)"(' . $keyPattern . ')\s*"\s*"(?=\s*[,}])/u',
            '$1"$2": ""',
            $json
        ) ?? $json;

        return preg_replace(
            '/(^|[{\[,]\s*)"(' . $keyPattern . ')"\s+(?=(?:"|\{|\[|-?\d|true\b|false\b|null\b))/u',
            '$1"$2": ',
            $json
        ) ?? $json;
    }

    /**
     * 从 position 处的 { 开始，取括号平衡的一段子串
     */
    public function extractBalancedBraces(string $str, int $start): ?string
    {
        $len = strlen($str);
        $depth = 0;
        $inString = false;
        $escape = false;
        $quote = '';
        $i = $start;

        while ($i < $len) {
            $c = $str[$i];
            if ($escape) {
                $escape = false;
                $i++;
                continue;
            }
            if ($inString) {
                if ($c === '\\') {
                    $escape = true;
                } elseif ($c === $quote) {
                    $inString = false;
                }
                $i++;
                continue;
            }
            if ($c === '"' || $c === "'") {
                $inString = true;
                $quote = $c;
                $i++;
                continue;
            }
            if ($c === '{') {
                $depth++;
                $i++;
                continue;
            }
            if ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($str, $start, $i - $start + 1);
                }
                $i++;
                continue;
            }
            $i++;
        }
        return null;
    }

    /**
     * 字符级遍历：修复 JSON 字符串值内的控制字符
     */
    public function fixControlCharsInJsonStrings(string $json): string
    {
        $len = strlen($json);
        $result = '';
        $inString = false;
        $i = 0;

        while ($i < $len) {
            $ch = $json[$i];

            if ($inString) {
                if ($ch === '\\' && $i + 1 < $len) {
                    $result .= $ch . $json[$i + 1];
                    $i += 2;
                    continue;
                }
                if ($ch === '"') {
                    $result .= $ch;
                    $inString = false;
                    $i++;
                    continue;
                }
                $ord = ord($ch);
                if ($ord < 0x20 || $ord === 0x7F) {
                    $result .= match ($ch) {
                        "\n" => '\\n',
                        "\r" => '\\r',
                        "\t" => '\\t',
                        "\x08" => '\\b',
                        "\x0C" => '\\f',
                        default => '\\u' . str_pad(dechex($ord), 4, '0', STR_PAD_LEFT),
                    };
                } else {
                    $result .= $ch;
                }
            } else {
                if ($ch === '"') {
                    $inString = true;
                }
                $result .= $ch;
            }
            $i++;
        }

        return $result;
    }

    /**
     * 修复被截断的 JSON：关闭未闭合的字符串、数组和对象
     */
    public function repairTruncatedJson(string $json): ?string
    {
        $json = $this->fixControlCharsInJsonStrings($json);
        $clean = preg_replace('/,\s*}/', '}', $json);
        $clean = preg_replace('/,\s*]/', ']', $clean);

        $trimmed = rtrim($clean);
        if (!str_starts_with($trimmed, '{')) {
            return null;
        }
        if (str_ends_with($trimmed, '}') && json_decode($trimmed, true) !== null && json_last_error() === JSON_ERROR_NONE) {
            return null;
        }

        $stack = [];
        $inString = false;
        $len = strlen($trimmed);

        for ($i = 0; $i < $len; $i++) {
            $ch = $trimmed[$i];
            if ($inString) {
                if ($ch === '\\' && $i + 1 < $len) {
                    $i++;
                    continue;
                }
                if ($ch === '"') {
                    $inString = false;
                }
                continue;
            }
            match ($ch) {
                '"' => $inString = true,
                '{' => $stack[] = '}',
                '[' => $stack[] = ']',
                '}' => array_pop($stack),
                ']' => array_pop($stack),
                default => null,
            };
        }

        if ($inString) {
            $trimmed = rtrim($trimmed);
            if (str_ends_with($trimmed, '\\') && !str_ends_with(rtrim($trimmed, " \t"), '\\\\')) {
                $trimmed .= '\\';
            }
            $trimmed .= '"';
        }

        $trimmed = rtrim($trimmed);
        if (str_ends_with($trimmed, ',')) {
            $trimmed = substr($trimmed, 0, -1);
        }
        $trimmed = preg_replace('/,\s*$/', '', $trimmed);

        while (!empty($stack)) {
            $trimmed .= array_pop($stack);
        }

        return $trimmed;
    }
}
