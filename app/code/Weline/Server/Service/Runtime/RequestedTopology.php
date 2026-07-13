<?php
declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

enum RequestedTopology: string
{
    case Auto = 'auto';
    case Direct = 'direct';
    case Dispatcher = 'dispatcher';
    case Independent = 'independent';

    public static function normalize(mixed $value): self
    {
        $value = \strtolower(\trim((string)$value));

        return self::tryFrom($value) ?? self::Auto;
    }
}
