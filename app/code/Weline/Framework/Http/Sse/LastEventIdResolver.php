<?php
declare(strict_types=1);

namespace Weline\Framework\Http\Sse;

final class LastEventIdResolver
{
    public static function resolve(object $request, string $queryKey = 'last_event_id'): int
    {
        $queryValue = self::readQueryValue($request, $queryKey);
        $headerValue = self::readHeaderValue($request);

        return \max(
            0,
            self::normalizeToPositiveInt($queryValue),
            self::normalizeToPositiveInt($headerValue)
        );
    }

    private static function readQueryValue(object $request, string $queryKey): mixed
    {
        if (\method_exists($request, 'getGet')) {
            try {
                return $request->getGet($queryKey, 0);
            } catch (\Throwable) {
            }
        }

        if (\method_exists($request, 'getParam')) {
            try {
                return $request->getParam($queryKey, 0);
            } catch (\Throwable) {
            }
        }

        return 0;
    }

    private static function readHeaderValue(object $request): mixed
    {
        if (\method_exists($request, 'getHeader')) {
            try {
                $header = $request->getHeader('Last-Event-ID');
                if ($header !== null && $header !== '') {
                    return $header;
                }
            } catch (\Throwable) {
            }
        }

        return \w_env('http_last_event_id', 0) ?: 0;
    }

    private static function normalizeToPositiveInt(mixed $value): int
    {
        if (\is_array($value)) {
            $value = \reset($value);
        }

        if ($value === null) {
            return 0;
        }

        $text = \trim((string) $value);
        if ($text === '' || !\preg_match('/^-?\d+$/', $text)) {
            return 0;
        }

        return \max(0, (int) $text);
    }
}
