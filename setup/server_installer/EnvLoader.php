<?php

declare(strict_types=1);

/**
 * Load weline.env (KEY=VALUE) into environment.
 * Single responsibility: parse env file only.
 */
final class EnvLoader
{
    private string $projectRoot;
    private ?string $envFile;

    public function __construct(string $projectRoot, ?string $envFile = null)
    {
        $this->projectRoot = $projectRoot;
        $this->envFile = $envFile;
    }

    /**
     * Load weline.env and return associative array; optionally putenv each.
     */
    public function load(bool $putenv = true): array
    {
        $path = $this->resolveEnvFilePath();
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

    public function resolveEnvFilePath(): string
    {
        $configured = $this->envFile;
        if ($configured === null || trim($configured) === '') {
            $fromEnv = getenv('WELINE_ENV_FILE');
            $configured = is_string($fromEnv) && trim($fromEnv) !== '' ? $fromEnv : 'weline.env';
        }
        $configured = trim($configured);
        if ($this->isAbsolutePath($configured)) {
            return $configured;
        }
        return $this->projectRoot . DIRECTORY_SEPARATOR . $configured;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1
            || str_starts_with($path, '\\\\');
    }
}
