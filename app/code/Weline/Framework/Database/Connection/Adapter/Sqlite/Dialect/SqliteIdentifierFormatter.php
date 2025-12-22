<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Connection\Adapter\Sqlite\Dialect;

use Weline\Framework\Database\Connection\Api\Sql\Dialect\IdentifierFormatterInterface;

class SqliteIdentifierFormatter implements IdentifierFormatterInterface
{
    public function normalize(string $identifier): string
    {
        $identifier = trim($identifier);
        return trim($identifier, '"');
    }

    public function quote(string $identifier): string
    {
        $identifier = $this->normalize($identifier);
        if ($identifier === '*') {
            return $identifier;
        }
        return sprintf('"%s"', $identifier);
    }

    public function quoteQualified(string ...$parts): string
    {
        $parts = array_filter($parts, static fn($part) => $part !== '');
        $parts = array_map([$this, 'quote'], $parts);
        return implode('.', $parts);
    }
}

