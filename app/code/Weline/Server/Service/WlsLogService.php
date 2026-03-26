<?php
declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Server\Log\Error\ErrorContext;

class WlsLogService
{
    private const DEFAULT_INSTANCE = 'default';
    private const DEFAULT_LOG_BASE_PATH = 'var/log/wls/';

    private static ?array $rawEnvConfigCache = null;

    /**
     * Returns instance-scoped log directory, always ending with DIRECTORY_SEPARATOR.
     */
    public static function getLogDir(
        ?string $instanceName = null,
        ?string $processTag = null,
        ?string $configuredPath = null
    ): string {
        $instance = self::resolveInstanceName($instanceName, $processTag);
        $templatePath = $configuredPath !== null && \trim($configuredPath) !== ''
            ? $configuredPath
            : self::getConfiguredBasePath();

        $absoluteTemplate = self::toAbsolutePath($templatePath);
        $resolved = self::applyInstanceToPath($absoluteTemplate, $instance);

        return \rtrim($resolved, "\\/") . DIRECTORY_SEPARATOR;
    }

    public static function getMainLogFile(
        ?string $instanceName = null,
        ?string $processTag = null,
        ?string $configuredPath = null
    ): string {
        return self::getLogDir($instanceName, $processTag, $configuredPath) . 'wls.log';
    }

    public static function getErrorLogFile(
        ?string $instanceName = null,
        ?string $processTag = null,
        ?string $configuredPath = null
    ): string {
        return self::getLogDir($instanceName, $processTag, $configuredPath) . 'error.log';
    }

    public static function getCrashLogFile(
        ?string $instanceName = null,
        ?string $processTag = null,
        ?string $configuredPath = null
    ): string {
        return self::getLogDir($instanceName, $processTag, $configuredPath) . 'crash.log';
    }

    public static function getWorkerLogFile(
        int $port,
        ?string $instanceName = null,
        ?string $processTag = null,
        ?string $configuredPath = null
    ): string {
        $safePort = $port > 0 ? $port : 0;
        return self::getLogDir($instanceName, $processTag, $configuredPath) . "worker-{$safePort}.log";
    }

    public static function getProcessLogFile(
        string $processName,
        ?string $instanceName = null,
        ?string $processTag = null,
        ?string $configuredPath = null
    ): string {
        $safeName = self::sanitizeFilename($processName, 'process');
        return self::getLogDir($instanceName, $processTag, $configuredPath) . $safeName . '.log';
    }

    /**
     * Resolve WLS instance name from explicit value, process tag, runtime context, or environment.
     */
    public static function resolveInstanceName(?string $instanceName = null, ?string $processTag = null): string
    {
        $candidate = self::pickFirstNonEmpty([
            $instanceName,
            self::extractInstanceFromProcessTag($processTag),
            self::getInstanceFromErrorContext(),
            self::getInstanceFromEnv(),
            self::getInstanceFromDefinedConstant(),
        ]);

        return self::sanitizeInstanceName($candidate ?? self::DEFAULT_INSTANCE);
    }

    public static function sanitizeInstanceName(string $instanceName): string
    {
        $instance = \trim($instanceName);
        if ($instance === '') {
            return self::DEFAULT_INSTANCE;
        }

        $instance = (string)\preg_replace('/[^A-Za-z0-9._-]+/', '_', $instance);
        $instance = \trim($instance, '._-');

        return $instance !== '' ? $instance : self::DEFAULT_INSTANCE;
    }

    public static function getConfiguredBasePath(): string
    {
        $raw = self::loadRawEnvConfig();
        $path = $raw['wls']['log']['path'] ?? self::DEFAULT_LOG_BASE_PATH;

        if (!\is_string($path) || \trim($path) === '') {
            return self::DEFAULT_LOG_BASE_PATH;
        }

        return $path;
    }

    public static function clearCache(): void
    {
        self::$rawEnvConfigCache = null;
    }

    private static function applyInstanceToPath(string $pathTemplate, string $instanceName): string
    {
        $normalized = \str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $pathTemplate);

        $placeholders = ['{instance}', '%instance%', ':instance'];
        foreach ($placeholders as $placeholder) {
            if (\str_contains($normalized, $placeholder)) {
                return \str_replace($placeholder, $instanceName, $normalized);
            }
        }

        $trimmed = \rtrim($normalized, DIRECTORY_SEPARATOR);
        if (self::lastSegmentEquals($trimmed, $instanceName)) {
            return $trimmed;
        }

        return $trimmed . DIRECTORY_SEPARATOR . $instanceName;
    }

    private static function toAbsolutePath(string $path): string
    {
        $trimmed = \trim($path);
        if ($trimmed === '') {
            $trimmed = self::DEFAULT_LOG_BASE_PATH;
        }

        if (self::isAbsolutePath($trimmed)) {
            return $trimmed;
        }

        if (\defined('BP')) {
            $base = \rtrim((string)BP, "\\/") . DIRECTORY_SEPARATOR;
            return $base . \ltrim($trimmed, "\\/");
        }

        return \rtrim(\sys_get_temp_dir(), "\\/") . DIRECTORY_SEPARATOR . \ltrim($trimmed, "\\/");
    }

    private static function isAbsolutePath(string $path): bool
    {
        if (\str_starts_with($path, '/') || \str_starts_with($path, '\\')) {
            return true;
        }

        return (bool)\preg_match('/^[A-Za-z]:[\\\\\\/]/', $path);
    }

    private static function pickFirstNonEmpty(array $values): ?string
    {
        foreach ($values as $value) {
            if (!\is_string($value)) {
                continue;
            }
            $trimmed = \trim($value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }
        return null;
    }

    private static function extractInstanceFromProcessTag(?string $processTag): ?string
    {
        if ($processTag === null || \trim($processTag) === '') {
            return null;
        }

        if (\preg_match('/@([^@\s]+)$/', $processTag, $matches)) {
            return $matches[1] ?? null;
        }

        return null;
    }

    private static function getInstanceFromErrorContext(): ?string
    {
        if (!\class_exists(ErrorContext::class)) {
            return null;
        }

        $instance = ErrorContext::get('instance');
        if (\is_string($instance) && \trim($instance) !== '') {
            return $instance;
        }

        $bootstrapInstance = ErrorContext::get('bootstrap_instance');
        if (\is_string($bootstrapInstance) && \trim($bootstrapInstance) !== '') {
            return $bootstrapInstance;
        }

        $tag = ErrorContext::getProcessTag();
        if (\is_string($tag) && \trim($tag) !== '') {
            return self::extractInstanceFromProcessTag($tag);
        }

        return null;
    }

    private static function getInstanceFromEnv(): ?string
    {
        $envCandidates = [
            \getenv('WLS_INSTANCE'),
            \getenv('WLS_INSTANCE_NAME'),
            $_ENV['WLS_INSTANCE'] ?? null,
            $_ENV['WLS_INSTANCE_NAME'] ?? null,
            $_SERVER['WLS_INSTANCE'] ?? null,
            $_SERVER['WLS_INSTANCE_NAME'] ?? null,
        ];

        return self::pickFirstNonEmpty($envCandidates);
    }

    private static function getInstanceFromDefinedConstant(): ?string
    {
        $constantCandidates = [];
        if (\defined('WLS_INSTANCE')) {
            $constantCandidates[] = \constant('WLS_INSTANCE');
        }
        if (\defined('WLS_INSTANCE_NAME')) {
            $constantCandidates[] = \constant('WLS_INSTANCE_NAME');
        }

        return self::pickFirstNonEmpty($constantCandidates);
    }

    private static function sanitizeFilename(string $raw, string $fallback): string
    {
        $clean = \trim($raw);
        $clean = (string)\preg_replace('/[^A-Za-z0-9._-]+/', '_', $clean);
        $clean = \trim($clean, '._-');

        return $clean !== '' ? $clean : $fallback;
    }

    private static function lastSegmentEquals(string $path, string $segment): bool
    {
        $normalized = \str_replace('\\', '/', $path);
        $normalized = \trim($normalized, '/');
        if ($normalized === '') {
            return false;
        }

        $parts = \explode('/', $normalized);
        $last = (string)\end($parts);

        return $last === $segment;
    }

    private static function loadRawEnvConfig(): array
    {
        if (self::$rawEnvConfigCache !== null) {
            return self::$rawEnvConfigCache;
        }

        $envFile = '';
        if (\defined('BP')) {
            $envFile = BP . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'env.php';
        } else {
            $envFile = \dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'env.php';
        }

        if ($envFile !== '' && \is_file($envFile)) {
            self::$rawEnvConfigCache = (array)@include $envFile;
        } else {
            self::$rawEnvConfigCache = [];
        }

        return self::$rawEnvConfigCache;
    }
}
