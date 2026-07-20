<?php

declare(strict_types=1);

namespace LearningMcp;

use JsonException;
use RuntimeException;

final class Clock
{
    public static function now(): string
    {
        $microseconds = microtime(true);
        $seconds = (int) floor($microseconds);
        $milliseconds = (int) floor(($microseconds - $seconds) * 1000);

        return gmdate('Y-m-d\\TH:i:s', $seconds) . sprintf('.%03dZ', $milliseconds);
    }
}

final class Json
{
    public static function encode(mixed $value, bool $pretty = false): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return json_encode($value, $flags);
    }

    /** @return array<string, mixed> */
    public static function object(string $value, string $label = 'JSON'): array
    {
        if (!str_starts_with(ltrim($value), '{')) {
            throw new RuntimeException($label . ' must be a JSON object');
        }
        try {
            $decoded = json_decode($value, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException($label . ': ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException($label . ' must be a JSON object');
        }

        return $decoded;
    }

    public static function decode(string $value, mixed $fallback = null): mixed
    {
        if (trim($value) === '') {
            return $fallback;
        }
        try {
            return json_decode($value, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $fallback;
        }
    }

    public static function canonical(mixed $value): string
    {
        return self::encode(self::sortRecursively($value));
    }

    private static function sortRecursively(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::sortRecursively(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::sortRecursively($item);
        }

        return $value;
    }
}

final class Ids
{
    public static function make(string $prefix): string
    {
        try {
            $random = bin2hex(random_bytes(8));
        } catch (\Throwable) {
            $random = dechex(hrtime(true));
        }

        return sprintf('%s-%d-%s', $prefix, (int) floor(microtime(true) * 1000), $random);
    }

    public static function hash(string $value): string
    {
        return 'sha256:' . hash('sha256', $value);
    }

    public static function deterministic(string $prefix, string $value, int $length = 26): string
    {
        return $prefix . '-' . substr(hash('sha256', $value), 0, $length);
    }
}

final class Text
{
    /** @param array<int, mixed> $values
     *  @return list<string>
     */
    public static function uniqueStrings(array $values, bool $sort = true): array
    {
        $seen = [];
        $result = [];
        foreach ($values as $value) {
            if (!is_scalar($value) && !$value instanceof \Stringable) {
                continue;
            }
            $text = trim((string) $value);
            if ($text === '' || isset($seen[$text])) {
                continue;
            }
            $seen[$text] = true;
            $result[] = $text;
        }
        if ($sort) {
            sort($result, SORT_STRING);
        }

        return $result;
    }

    public static function truncate(string $value, int $limit): string
    {
        $value = trim($value);
        if (mb_strlen($value, 'UTF-8') <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit, 'UTF-8') . '…';
    }

    public static function normalize(string $value): string
    {
        $value = mb_strtolower($value, 'UTF-8');
        return preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? '';
    }

    public static function similarity(string $query, string $candidate): float
    {
        $query = self::normalize($query);
        $candidate = self::normalize($candidate);
        if ($query === '') {
            return 0.0;
        }
        if (str_contains($candidate, $query)) {
            return 1.0;
        }
        $queryBigrams = self::bigrams($query);
        if ($queryBigrams === []) {
            return 0.0;
        }
        $candidateBigrams = array_fill_keys(self::bigrams($candidate), true);
        $matched = 0;
        foreach ($queryBigrams as $bigram) {
            if (isset($candidateBigrams[$bigram])) {
                ++$matched;
            }
        }

        return $matched / count($queryBigrams);
    }

    /** @return list<string> */
    private static function bigrams(string $value): array
    {
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($characters) === 1) {
            return [$characters[0]];
        }
        $result = [];
        for ($index = 0, $last = count($characters) - 1; $index < $last; ++$index) {
            $result[] = $characters[$index] . $characters[$index + 1];
        }

        return array_values(array_unique($result));
    }

    public static function globMatches(string $pattern, string $path): bool
    {
        $pattern = ltrim(str_replace('\\', '/', trim($pattern)), './');
        $path = ltrim(str_replace('\\', '/', trim($path)), './');
        $quoted = preg_quote($pattern, '~');
        $quoted = str_replace(['\\*\\*', '\\*', '\\?'], ['.*', '[^/]*', '[^/]'], $quoted);

        return preg_match('~^' . $quoted . '$~u', $path) === 1;
    }

    /** @param list<string> $patterns
     *  @param list<string> $paths
     */
    public static function anyPathMatches(array $patterns, array $paths): bool
    {
        if ($patterns === []) {
            return true;
        }
        if ($paths === []) {
            return false;
        }
        foreach ($patterns as $pattern) {
            foreach ($paths as $path) {
                if (self::globMatches($pattern, $path)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string, mixed> $value */
    public static function findRecursive(array $value, string $key): mixed
    {
        foreach ($value as $current => $item) {
            if (strcasecmp((string) $current, $key) === 0) {
                return $item;
            }
            if (is_array($item)) {
                $found = self::findRecursive($item, $key);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }
}

final class Redactor
{
    private const REDACTED = '[REDACTED]';

    /** @return array{0:string,1:int} */
    public static function string(string $value): array
    {
        $count = 0;
        $patterns = [
            '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s',
            '/\bBearer\s+[A-Za-z0-9._~+\/-]{12,}={0,2}/i',
            '/\b(?:sk|rk|pk)-(?:proj-)?[A-Za-z0-9_-]{12,}\b/',
            '/\b(?:ghp|gho|ghu|ghs|github_pat)_[A-Za-z0-9_]{20,}\b/',
            '/\bAKIA[0-9A-Z]{16}\b/',
            '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/',
            '/(?i)(\b(?:api[_-]?key|access[_-]?token|auth[_-]?token|client[_-]?secret|password|passwd|secret)\b\s*[:=]\s*)(["\']?)[^\s,"\']{6,}\2/',
        ];
        foreach ($patterns as $pattern) {
            $value = preg_replace_callback(
                $pattern,
                static function (array $matches) use (&$count): string {
                    ++$count;
                    return isset($matches[1]) ? $matches[1] . self::REDACTED : self::REDACTED;
                },
                $value,
            ) ?? $value;
        }

        return [$value, $count];
    }

    /** @return array{0:mixed,1:int} */
    public static function value(mixed $value, ?string $key = null): array
    {
        if ($key !== null && self::sensitiveKey($key)) {
            return [self::REDACTED, 1];
        }
        if (is_string($value)) {
            return self::string($value);
        }
        if (!is_array($value)) {
            return [$value, 0];
        }
        $count = 0;
        $result = [];
        foreach ($value as $current => $item) {
            [$redacted, $itemCount] = self::value($item, is_string($current) ? $current : null);
            $result[$current] = $redacted;
            $count += $itemCount;
        }

        return [$result, $count];
    }

    public static function looksLikeInjection(string $value): bool
    {
        $value = mb_strtolower($value, 'UTF-8');
        foreach ([
            'ignore previous instructions', 'ignore all previous', 'system prompt', 'developer message',
            'reveal your instructions', 'upload secrets', 'exfiltrate', 'bypass policy',
            '忽略之前的指令', '忽略所有指令', '泄露系统提示', '上传密钥', '绕过安全策略',
        ] as $needle) {
            if (str_contains($value, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function sensitiveKey(string $key): bool
    {
        $key = strtolower(str_replace(['-', ' '], '_', trim($key)));
        return preg_match('/(?:^|_)(?:api_?key|token|password|passwd|secret|cookie|authorization|private_?key)(?:$|_)/', $key) === 1;
    }
}

final class ToolException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly bool $retryable = false,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /** @return array<string, mixed> */
    public function envelope(?string $requestId = null): array
    {
        return [
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
                'retryable' => $this->retryable,
                'details' => $this->details,
            ],
            'request_id' => $requestId ?? Ids::make('req'),
        ];
    }
}
