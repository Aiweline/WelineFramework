<?php

declare(strict_types=1);

namespace Weline\Storage\Service;

use Weline\Framework\Runtime\RequestResetException;
use Weline\Framework\Runtime\RequestResetterInterface;
use Weline\Storage\Api\Runtime\StorageRequestResourceRegistryInterface;
use Weline\Framework\Runtime\ProcessSharedInterface;


final class StorageRequestResetter implements ProcessSharedInterface, RequestResetterInterface
{
    public function __construct(
        private readonly StorageRequestResourceRegistryInterface $resources,
        private readonly StorageManagerV2 $storageManager,
    ) {
    }

    public function resetRequest(): void
    {
        $failures = [];
        try {
            $this->resources->closeAll();
        } catch (\Throwable $throwable) {
            RequestResetException::append($failures, 'request_resources', $throwable);
        }

        try {
            $this->storageManager->resetRequestState();
        } catch (\Throwable $throwable) {
            RequestResetException::append($failures, 'storage_manager_state', $throwable);
        }

        if ($failures !== []) {
            throw new RequestResetException('storage_request_resetter', $failures);
        }
    }
}
