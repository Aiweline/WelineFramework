<?php

declare(strict_types=1);

namespace Weline\Database\Service;

/**
 * @deprecated Use \Weline\Framework\Database\Service\BackupService.
 *
 * Kept as a compatibility service so existing commands and third-party
 * migrations resolve the Framework-owned implementation without duplicating
 * table, column, composite-primary-key, or restore logic.
 */
class BackupService extends \Weline\Framework\Database\Service\BackupService
{
}
