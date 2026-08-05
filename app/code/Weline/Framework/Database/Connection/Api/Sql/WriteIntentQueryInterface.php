<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Connection\Api\Sql;

/** Optional capability for roots that must own write intent before reading. */
interface WriteIntentQueryInterface extends QueryInterface
{
    public function beginWriteTransaction(): void;
}
