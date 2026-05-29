<?php
declare(strict_types=1);

$root = dirname(__DIR__, 5);
require_once $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

defined('DS') || define('DS', DIRECTORY_SEPARATOR);
defined('BP') || define('BP', $root . DIRECTORY_SEPARATOR);
defined('APP_CODE_PATH') || define('APP_CODE_PATH', BP . 'app' . DS . 'code' . DS);

if (!function_exists('__')) {
    function __(string $text, array $params = []): string
    {
        foreach ($params as $key => $value) {
            $placeholder = is_int($key) ? '%{' . ($key + 1) . '}' : '%{' . $key . '}';
            $text = str_replace($placeholder, (string)$value, $text);
        }

        return $text;
    }
}

if (!function_exists('w_env')) {
    function w_env(string $key, mixed $default = null): mixed
    {
        return $default;
    }
}
