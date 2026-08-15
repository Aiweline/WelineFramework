<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Connection\Api;

/** Exact catalog identity for a physical view. */
final readonly class PhysicalViewIdentity
{
    public static function fromCanonical(string $canonical): self
    {
        $parts = explode('.', $canonical);
        if (count($parts) !== 2) {
            throw new \InvalidArgumentException('invalid canonical physical view identity');
        }
        return new self($parts[0], $parts[1]);
    }

    public function __construct(
        private string $namespace,
        private string $view,
    ) {
        $this->assertSegment($namespace, 'namespace');
        $this->assertSegment($view, 'view');
    }

    public function namespace(): string
    {
        return $this->namespace;
    }

    public function view(): string
    {
        return $this->view;
    }

    public function canonical(): string
    {
        return $this->namespace . '.' . $this->view;
    }

    private function assertSegment(string $segment, string $label): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $segment) !== 1) {
            throw new \InvalidArgumentException("invalid physical view {$label}");
        }
    }
}
