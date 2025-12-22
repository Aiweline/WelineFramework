<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Connection\Api\Sql\Dialect;

/**
 * MySQL 风格的标识符引号（ `identifier` ）。
 */
class DefaultIdentifierFormatter implements IdentifierFormatterInterface
{
    public function normalize(string $identifier): string
    {
        $identifier = trim($identifier);
        // 移除已存在的反引号
        return str_replace('`', '', $identifier);
    }

    public function quote(string $identifier): string
    {
        $identifier = $this->normalize($identifier);
        if ($identifier === '*') {
            return $identifier;
        }
        return sprintf('`%s`', $identifier);
    }

    public function quoteQualified(string ...$parts): string
    {
        $parts = array_filter($parts, static fn($part) => $part !== '');
        $parts = array_map([$this, 'quote'], $parts);
        return implode('.', $parts);
    }
}

