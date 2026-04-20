<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server;

if (!\function_exists(__NAMESPACE__ . '\\__')) {
    function __(string $message, array $args = []): string
    {
        $translated = $message;
        foreach (\array_values($args) as $index => $value) {
            $translated = \str_replace('%{' . ($index + 1) . '}', (string)$value, $translated);
            $translated = \str_replace('%' . ($index + 1), (string)$value, $translated);
        }

        return $translated;
    }
}
