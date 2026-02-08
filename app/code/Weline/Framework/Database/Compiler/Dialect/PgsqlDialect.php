<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Compiler\Dialect;

/**
 * PostgreSQL 16+ 方言
 * @since 1.0.0 支持 PostgreSQL 16+
 */
final class PgsqlDialect implements DialectInterface
{
    private const SINCE_VERSION = '16.0';

    public function quoteIdentifier(string $identifier): string
    {
        $identifier = trim(str_replace(['"', '`'], '', $identifier));
        if ($identifier === '*' || $identifier === '') {
            return $identifier ?: '"' . $identifier . '"';
        }
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    public function quoteTable(string $table, string $alias = ''): string
    {
        $table = trim(str_replace(['"', '`'], '', $table));
        if ($table === '') {
            throw new \InvalidArgumentException('Table name cannot be empty');
        }
        $parts = array_values(array_filter(array_map('trim', explode('.', $table)), fn(string $p): bool => $p !== ''));
        if (count($parts) > 2) {
            $parts = array_slice($parts, -2);
        }
        $quoted = '"' . implode('"."', array_map(fn(string $p): string => str_replace('"', '""', $p), $parts)) . '"';
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
        return 'CURRENT_TIMESTAMP';
    }

    public function booleanLiteral(bool $value): string
    {
        return $value ? 'TRUE' : 'FALSE';
    }

    public function getSinceVersion(): string
    {
        return self::SINCE_VERSION;
    }

    public function getDriverType(): string
    {
        return 'pgsql';
    }

    public function validateVersion(string $serverVersion): void
    {
        $v = $this->parseVersion($serverVersion);
        $min = $this->parseVersion(self::SINCE_VERSION);
        if ($v !== null && $min !== null && version_compare((string)$v, (string)$min, '<')) {
            throw new \RuntimeException(
                sprintf('PostgreSQL version %s is not supported. Minimum required: %s', $serverVersion, self::SINCE_VERSION)
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
