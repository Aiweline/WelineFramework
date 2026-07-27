<?php

declare(strict_types=1);

namespace Weline\Server\IPC\ChildControl;

/**
 * Optional capability for child-control transports that must run a
 * process-owned fail-closed check immediately before every READY send.
 */
interface BeforeReadyGuardAwareClientInterface
{
    /**
     * @param null|callable(string): void $guard Receives the resolved process role.
     */
    public function setBeforeReadyGuard(?callable $guard): void;
}
