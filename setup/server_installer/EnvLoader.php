<?php

declare(strict_types=1);

/**
 * Load weline.env (KEY=VALUE) into environment.
 * Single responsibility: parse env file only.
 */
final class EnvLoader
{
    private string $projectRoot;

    public function __construct(string $projectRoot)
    {
        $this->projectRoot = $projectRoot;
    }

    /**
     * Load weline.env and return associative array; optionally putenv each.
     */
    public function load(bool $putenv = true): array
    {
        $path = $this->projectRoot . DIRECTORY_SEPARATOR . 'weline.env';
        $vars = [];
        if (!is_file($path)) {
            return $vars;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m)) {
                $key = $m[1];
                $value = trim($m[2]);
                $vars[$key] = $value;
                if ($putenv) {
                    putenv("$key=$value");
                }
            }
        }
        return $vars;
    }
}
