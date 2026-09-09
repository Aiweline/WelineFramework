<?php

declare(strict_types=1);

if (!function_exists('__')) {
    function __(string $text, mixed $params = []): string
    {
        foreach (is_array($params) ? $params : [$params] as $index => $value) {
            $text = str_replace('%{' . ($index + 1) . '}', (string)$value, $text);
        }
        return $text;
    }
}

require dirname(__DIR__) . '/Unit/bootstrap.php';
$sourceRoot = dirname(__DIR__, 6) . '/app/code/';
// 验证工作区实际源码，避免 Composer 的旧 vendor 模块抢先覆盖。
spl_autoload_register(static function (string $class) use ($sourceRoot): void {
    if (str_starts_with($class, 'Weline\\')) {
        $file = $sourceRoot . str_replace('\\', '/', $class) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
}, true, true);
