<?php

declare(strict_types=1);

namespace Weline\Server\Security;

/** A transport-neutral instruction returned by ConnectionAcceptGate::sweep(). */
final readonly class ConnectionCloseDirective
{
    public function __construct(
        public string $connectionId,
        public string $reason,
        public string $peerIp,
    ) {
    }
}
