<?php

declare(strict_types=1);

if (!\function_exists('__')) {
    function __(string $text, array $args = []): string
    {
        $out = $text;
        foreach ($args as $index => $value) {
            $out = str_replace('%{' . ($index + 1) . '}', (string)$value, $out);
        }

        return $out;
    }
}
