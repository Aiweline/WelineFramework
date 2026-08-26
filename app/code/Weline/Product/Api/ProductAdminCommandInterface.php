<?php

declare(strict_types=1);

namespace Weline\Product\Api;

use Weline\Product\Api\Data\ProductAdminCommand;
use Weline\Product\Api\Data\ProductAdminResult;

/** Product-owned write boundary for all backend catalog mutations. */
interface ProductAdminCommandInterface
{
    public function execute(ProductAdminCommand $command): ProductAdminResult;
}
