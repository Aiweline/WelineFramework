<?php

declare(strict_types=1);

namespace Weline\Framework\Event\Async;

use Weline\Framework\Event\Async\Exception\AsyncEventValidationException;

final class CanonicalJson
{
    public const MAX_BYTES = 49152;
    public const MAX_DEPTH = 16;

    public function encode(mixed $value, bool $rejectSensitiveKeys = true): string
    {
        $this->assertValue($value, 0, '$', $rejectSensitiveKeys);
        try {
            $json = json_encode(
                $this->canonicalize($value),
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $exception) {
            throw new AsyncEventValidationException(
                __('异步事件载荷无法编码为 JSON：%{1}', [$exception->getMessage()]),
                previous: $exception,
            );
        }
        if (strlen($json) > self::MAX_BYTES) {
            throw new AsyncEventValidationException(
                __('异步事件载荷超过 %{1} 字节限制', [self::MAX_BYTES]),
            );
        }
        return $json;
    }

    public function hash(mixed $value, bool $rejectSensitiveKeys = true): string
    {
        return hash('sha256', $this->encode($value, $rejectSensitiveKeys));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }

    private function assertValue(mixed $value, int $depth, string $path, bool $rejectSensitiveKeys): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new AsyncEventValidationException(
                __('异步事件载荷层级超过 %{1}：%{2}', [self::MAX_DEPTH, $path]),
            );
        }
        if (is_object($value) || is_resource($value)) {
            throw new AsyncEventValidationException(__('异步事件载荷只允许 JSON 标量和数组：%{1}', [$path]));
        }
        if (is_float($value) && !is_finite($value)) {
            throw new AsyncEventValidationException(__('异步事件载荷不允许非有限浮点数：%{1}', [$path]));
        }
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $key => $item) {
            if (!is_int($key) && !is_string($key)) {
                throw new AsyncEventValidationException(__('异步事件载荷包含非法键：%{1}', [$path]));
            }
            $keyString = (string)$key;
            if ($rejectSensitiveKeys && preg_match(
                '/(?:password|passwd|secret|token|cookie|session|csrf|authorization|credential|private[_-]?key|api[_-]?key|access[_-]?key|signature)/i',
                $keyString,
            )) {
                throw new AsyncEventValidationException(__('异步事件载包含禁止的敏感字段：%{1}', [$path . '.' . $keyString]));
            }
            $this->assertValue($item, $depth + 1, $path . '.' . $keyString, $rejectSensitiveKeys);
        }
    }
}
