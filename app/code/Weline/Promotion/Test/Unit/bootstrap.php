<?php

declare(strict_types=1);

if (!function_exists('__')) {
    function __(string $text, array $args = []): string
    {
        if ($args === []) {
            return $text;
        }

        $result = $text;
        foreach ($args as $index => $value) {
            $placeholder = is_int($index)
                ? '%{' . ($index + 1) . '}'
                : '%{' . $index . '}';
            $result = str_replace($placeholder, (string)$value, $result);
        }

        return $result;
    }
}
