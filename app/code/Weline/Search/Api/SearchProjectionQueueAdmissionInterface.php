<?php

declare(strict_types=1);

namespace Weline\Search\Api;

use Weline\Framework\Runtime\ScopeIdentity;

/**
 * Admits Product Search projection changes into coalesced Queue slots.
 */
interface SearchProjectionQueueAdmissionInterface
{
    /**
     * @param array{
     *   contract:string,
     *   event_id:string,
     *   event_seq:int,
     *   target_type:string,
     *   target_id:int
     * } $payload
     */
    public function admit(array $payload, ScopeIdentity $scope): void;
}
