<?php

declare(strict_types=1);

namespace Weline\Websites\Console\Website;

final class CliJson
{
    public static function requested(array $args): bool
    {
        if (isset($args['json']) || isset($args['j']) || isset($args['--json'])) {
            return true;
        }
        foreach (($_SERVER['argv'] ?? []) as $arg) {
            if (!\is_string($arg)) {
                continue;
            }
            if ($arg === '--json' || $arg === '-j') {
                return true;
            }
        }

        return false;
    }

    public static function emit(mixed $payload): int
    {
        $json = \json_encode(
            $payload,
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE,
        );
        if ($json === false) {
            \fwrite(\STDERR, 'json_encode failed' . \PHP_EOL);

            return 1;
        }
        echo $json, \PHP_EOL;

        return 0;
    }
}
