<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Schema;

/**
 * The active checkpoint row exists but its persisted checkpoint contract is
 * invalid. Kept distinct from database/runtime failures so DEV rebind cannot
 * accidentally mask infrastructure errors.
 */
final class SchemaCheckpointDataException extends \RuntimeException
{
}
