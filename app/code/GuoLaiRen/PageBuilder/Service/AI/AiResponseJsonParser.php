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
        // 容错：如果响应包含 markdown 标题（AI 未遵守 JSON-only 约束），先提取 JSON 部分
        $response = $this->stripMarkdownPrefix($response);
        $json = $this->extractJson($response);
        return $json !== null ? $this->decodeWithRepair($json) : null;
    }

    /**
     * 容错处理：AI 可能返回 markdown 格式的开头（如 "# Site Blueprint"），
     * 需要跳过这些非 JSON 内容再提取 JSON。
     */
    private function stripMarkdownPrefix(string $response): string
    {
        $trimmed = \trim($response);
        // 如果以 # 或 ``` 开头，说明 AI 没有严格返回纯 JSON，尝试找到真正的 JSON 开始位置
        if ($trimmed !== '' && ($trimmed[0] === '#' || \str_starts_with($trimmed, '```'))) {
            // 查找第一个 { 的位置
            $bracePos = \strpos($response, '{');
            if ($bracePos !== false && $bracePos > 0) {
                $jsonStart = \substr($response, $bracePos);
                // 跳过可能的 ```json 前的空白
                $jsonStart = \ltrim($jsonStart);
                // 验证截取的字符串看起来像 JSON
                if (\str_starts_with($jsonStart, '{')) {
                    return $jsonStart;
                }
            }
        }
        return $response;
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

        if (str_starts_with($response, '{') && str_ends_with($response, '}')) {
            return $response;
        }

        if (str_starts_with($response, '{')) {
            $repaired = $this->repairTruncatedJson($response);
            if ($repaired !== null) {
                $decoded = json_decode($repaired, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    return $repaired;
                }
            }
        }

        if ($firstBrace !== false) {
            $extracted = $this->extractBalancedBraces($response, $firstBrace);
            if ($extracted !== null) {
                return $extracted;
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
        $normalized = preg_replace('/,\s*}/', '}', $normalized);
        $normalized = preg_replace('/,\s*]/', ']', $normalized);
        $data = json_decode($normalized, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            return $data;
        }

        // 第二次：对原始 $json 再做一次相同修复后解析（兜底）
        $fixed = $this->fixControlCharsInJsonStrings($json);
        $fixed = preg_replace('/,\s*}/', '}', $fixed);
        $fixed = preg_replace('/,\s*]/', ']', $fixed);
        $data = json_decode($fixed, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            return $data;
        }

        $fixed = preg_replace('/[\x00-\x1F\x7F]/u', '', $json);
        $fixed = preg_replace('/,\s*}/', '}', $fixed);
        $fixed = preg_replace('/,\s*]/', ']', $fixed);
        $data = json_decode($fixed, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            return $data;
        }

        $normalizedForRepair = $this->fixControlCharsInJsonStrings($json);
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

        if (!$inString && empty($stack)) {
            return null;
        }

        if ($inString) {
            $trimmed = rtrim($trimmed);
            if (str_ends_with($trimmed, '\\') && !str_ends_with(rtrim($trimmed, " \t"), '\\\\')) {
                $trimmed .= '\\';
            }
            $trimmed .= '"';
        }

        // 如果字符串仍在 JSON 结构内部（stack 不为空），需要先关闭所有未闭合的括号
        // 这处理了 markdown 字段等长字符串值被截断的情况
        while (!empty($stack)) {
            $trimmed .= array_pop($stack);
        }

        $trimmed = rtrim($trimmed);
        if (str_ends_with($trimmed, ',')) {
            $trimmed = substr($trimmed, 0, -1);
        }
        $trimmed = preg_replace('/,\s*$/', '', $trimmed);

        return $trimmed;
    }
}
