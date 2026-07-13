<?php

declare(strict_types=1);

namespace Weline\Framework\Setup\Stage;

use Weline\Framework\Setup\Db\ModelSetup;

interface EavSchemaProviderInterface
{
    /** Owning module resource name, for example Vendor_Module. */
    public function ownerModuleName(): string;

    /** @return list<string> Unprefixed table names. */
    public function createTables(ModelSetup $setup): array;
}
