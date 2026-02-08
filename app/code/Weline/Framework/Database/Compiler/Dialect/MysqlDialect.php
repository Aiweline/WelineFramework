<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Compiler\Dialect;

/**
 * MySQL 8.0+ 方言
 * @since 1.0.0 支持 MySQL 8.0+
 */
final class MysqlDialect implements DialectInterface
{
    private const SINCE_VERSION = '8.0';

    public function quoteIdentifier(string $identifier): string
    {
        $identifier = trim(str_replace(['`', '"'], '', $identifier));
        if ($identifier === '*') {
            return '*';
        }
        if ($identifier === '') {
            return '';
        }
        return '`' . $identifier . '`';
    }

    public function quoteTable(string $table, string $alias = ''): string
    {
        $table = trim(str_replace(['`', '"'], '', $table));
        if ($table === '') {
            throw new \InvalidArgumentException('Table name cannot be empty');
        }
        $parts = array_values(array_filter(array_map('trim', explode('.', $table)), fn(string $p): bool => $p !== ''));
        if (count($parts) > 2) {
            $parts = array_slice($parts, -2);
        }
        $quoted = '`' . implode('`.`', $parts) . '`';
        if ($alias !== '') {
            $alias = trim(str_replace(['`', '"'], '', $alias));
            $quoted .= ' AS `' . $alias . '`';
        }
        return $quoted;
    }

    public function supportsReturning(): bool
    {
        return false;
    }

    public function limitOffset(int $limit, int $offset): string
    {
        return " LIMIT {$offset},{$limit}";
    }

    public function currentTimestamp(): string
    {
        return 'NOW()';
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
        return 'mysql';
    }

    public function validateVersion(string $serverVersion): void
    {
        $v = $this->parseVersion($serverVersion);
        $min = $this->parseVersion(self::SINCE_VERSION);
        if ($v !== null && $min !== null && version_compare((string)$v, (string)$min, '<')) {
            throw new \RuntimeException(
                sprintf('MySQL version %s is not supported. Minimum required: %s', $serverVersion, self::SINCE_VERSION)
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
