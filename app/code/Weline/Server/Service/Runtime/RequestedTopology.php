<?php
declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

enum RequestedTopology: string
{
    case Auto = 'auto';
    case Direct = 'direct';
    case Dispatcher = 'dispatcher';
}
