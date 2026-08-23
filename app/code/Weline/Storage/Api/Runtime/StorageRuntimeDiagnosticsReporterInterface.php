<?php

declare(strict_types=1);

namespace Weline\Storage\Api\Runtime;

/** Path/URL/secret-free runtime residue reporting boundary. */
interface StorageRuntimeDiagnosticsReporterInterface
{
    public function operationResidue(string $reason): void;
}
