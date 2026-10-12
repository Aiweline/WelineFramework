<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Compiler\Dialect;

/**
 * SQLite 3.45+ 方言
 * @since 1.0.0 支持 SQLite 3.45+
 */
final class SqliteDialect implements DialectInterface
{
    private const SINCE_VERSION = '3.45';

    public function quoteIdentifier(string $identifier): string
    {
        $identifier = trim(str_replace(['"', '`', '[', ']'], '', $identifier));
        if ($identifier === '*' || $identifier === '') {
            return $identifier ?: '"' . $identifier . '"';
        }
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    public function quoteTable(string $table, string $alias = ''): string
    {
        $table = trim(str_replace(['"', '`', '[', ']'], '', $table));
        if ($table === '') {
            throw new \InvalidArgumentException('Table name cannot be empty');
        }
        if (str_contains($table, '.')) {
            $parts = array_values(array_filter(
                array_map('trim', explode('.', $table)),
                static fn(string $part): bool => $part !== '',
            ));
            $table = (string)end($parts);
        }
        $quoted = '"' . str_replace('"', '""', $table) . '"';
        if ($alias !== '') {
            $alias = trim(str_replace(['"', '`'], '', $alias));
            $quoted .= ' AS "' . str_replace('"', '""', $alias) . '"';
        }
        return $quoted;
    }

    public function supportsReturning(): bool
    {
        return true;
    }

    public function limitOffset(int $limit, int $offset): string
    {
        return " LIMIT {$limit} OFFSET {$offset}";
    }

    public function currentTimestamp(): string
    {
        return "datetime('now')";
    }

    public function unixEpochExpression(bool $utc = true): string
    {
        // SQLite datetime('now') 为 UTC；无独立会话时区时钟
        return "CAST(strftime('%s','now') AS INTEGER)";
    }

    public function timeBucketExpression(string $timestampExpr, int $intervalMinutes): string
    {
        $intervalMinutes = max(1, $intervalMinutes);

        return "strftime('%Y-%m-%d %H:', {$timestampExpr})"
            . " || printf('%02d', (CAST(strftime('%M', {$timestampExpr}) AS INTEGER) / {$intervalMinutes}) * {$intervalMinutes})"
            . " || ':00'";
    }

    public function jsonExtractText(string $columnExpr, string $path): string
    {
        $segments = array_values(array_filter(
            array_map('trim', explode('.', str_replace(['$', '"', "'"], '', $path))),
            static fn(string $part): bool => $part !== '',
        ));
        if ($segments === []) {
            throw new \InvalidArgumentException('JSON path cannot be empty');
        }
        $sqlitePath = '$.' . implode('.', $segments);

        return "json_extract({$columnExpr}, '{$sqlitePath}')";
    }

    public function booleanLiteral(bool $value): string
    {
        return $value ? '1' : '0';
    }

    public function getSinceVersion(): string
    {
        return self::SINCE_VERSION;
    }

    public function getDriverType(): string
    {
        return 'sqlite';
    }

    public function validateVersion(string $serverVersion): void
    {
        $v = $this->parseVersion($serverVersion);
        $min = $this->parseVersion(self::SINCE_VERSION);
        if ($v !== null && $min !== null && version_compare((string)$v, (string)$min, '<')) {
            throw new \RuntimeException(
                sprintf('SQLite version %s is not supported. Minimum required: %s', $serverVersion, self::SINCE_VERSION)
            );
        }
    }

    private function parseVersion(string $version): ?string
    {
        if (preg_match('/^(\d+\.\d+)/', trim($version), $m)) {
            return $m[1];
        }
        return null;
    }
}
