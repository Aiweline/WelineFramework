<?php
declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

enum EffectiveTopology: string
{
    case Direct = 'direct';
    case Dispatcher = 'dispatcher';
    case Independent = 'independent';

    public function isDirect(): bool
    {
        return $this === self::Direct;
    }

    public function isDispatcher(): bool
    {
        return $this === self::Dispatcher;
    }
}
