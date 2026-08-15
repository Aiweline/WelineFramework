<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Connection\Api\Sql;

use Weline\Framework\Database\Connection\Api\PhysicalTableIdentity;

/** Optional exact-physical query capability; intentionally not part of QueryInterface. */
interface PhysicalTableQueryInterface
{
    public function tablePhysical(PhysicalTableIdentity $identity): QueryInterface;
}
