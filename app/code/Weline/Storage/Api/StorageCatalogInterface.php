<?php

declare(strict_types=1);

namespace Weline\Storage\Api;

use Weline\Framework\Runtime\ScopeIdentity;

interface StorageCatalogInterface
{
    /**
     * @return list<array{name:string,driver:string,is_default:bool,info:array<string,mixed>,media_base_url?:string}>
     */
    public function all(?ScopeIdentity $scope = null): array;
}
