<?php

declare(strict_types=1);

namespace Weline\Storage\Api;

interface StorageCatalogInterface
{
    /** @return list<array{name:string,driver:string,is_default:bool,info:array<string,mixed>}> */
    public function all(): array;
}
