<?php

declare(strict_types=1);

namespace Weline\Storage\Service;

use Weline\Storage\Api\Runtime\StorageRuntimeDiagnosticsReporterInterface;

final class StorageRuntimeDiagnosticsReporter implements StorageRuntimeDiagnosticsReporterInterface
{
    public function operationResidue(string $reason): void
    {
        StorageRuntimeDiagnostics::operationResidue($reason);
    }
}
