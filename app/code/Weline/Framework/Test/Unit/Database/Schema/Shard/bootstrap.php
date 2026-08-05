<?php

declare(strict_types=1);

/**
 * Minimal bootstrap for isolated Framework Schema unit tests.
 */
if (!\function_exists('__')) {
    function __(string $text, array $params = []): string
    {
        $out = $text;
        foreach ($params as $i => $value) {
            $out = str_replace('%{' . ($i + 1) . '}', (string)$value, $out);
            if (\is_string($i) || !\is_int($i)) {
                $out = str_replace('%{' . $i . '}', (string)$value, $out);
            }
        }
        return $out;
    }
}

if (!\defined('BP')) {
    \define('BP', \dirname(__DIR__, 7) . DIRECTORY_SEPARATOR);
}
if (!\defined('DS')) {
    \define('DS', DIRECTORY_SEPARATOR);
}

$autoload = \dirname(__DIR__, 7) . '/vendor/autoload.php';
if (\is_file($autoload)) {
    require_once $autoload;
}
