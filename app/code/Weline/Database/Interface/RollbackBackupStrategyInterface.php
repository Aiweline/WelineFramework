<?php

declare(strict_types=1);

namespace Weline\Database\Interface;

/**
 * @deprecated Prefer the Framework-owned interface. This alias keeps the
 * public Weline_Database migration namespace source-compatible.
 */
interface RollbackBackupStrategyInterface extends
    \Weline\Framework\Database\Migration\RollbackBackupStrategyInterface,
    MigrationInterface
{
}
