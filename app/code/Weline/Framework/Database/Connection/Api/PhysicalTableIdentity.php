<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Connection\Api;

/**
 * Exact catalog identity for a physical table.
 *
 * Both segments are unquoted identifiers. Logical-name prefixing and runtime
 * namespace selection must happen before this value is created.
 */
final readonly class PhysicalTableIdentity
{
    public static function fromCanonical(string $canonical): self
    {
        $parts = explode('.', $canonical);
        if (count($parts) !== 2) {
            throw new \InvalidArgumentException('invalid canonical physical table identity');
        }
        return new self($parts[0], $parts[1]);
    }

    public function __construct(
        private string $namespace,
        private string $table,
    ) {
        $this->assertSegment($namespace, 'namespace');
        $this->assertSegment($table, 'table');
    }

    public function namespace(): string
    {
        return $this->namespace;
    }

    public function table(): string
    {
        return $this->table;
    }

    public function canonical(): string
    {
        return $this->namespace . '.' . $this->table;
    }

    private function assertSegment(string $segment, string $label): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $segment) !== 1) {
            throw new \InvalidArgumentException("invalid physical table {$label}");
        }
    }
}
