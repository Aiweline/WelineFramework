<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Connection\Adapter\Pgsql\Dialect;

use Weline\Framework\Database\Connection\Api\Sql\Dialect\IdentifierFormatterInterface;

/**
 * PostgreSQL 要求使用双引号包裹标识符。
 */
class PgsqlIdentifierFormatter implements IdentifierFormatterInterface
{
    public function normalize(string $identifier): string
    {
        $identifier = trim($identifier);
        // 移除所有引号（包括反引号和双引号），确保规范化后的标识符不包含引号
        $identifier = str_replace(['`', '"'], '', $identifier);
        return $identifier;
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

