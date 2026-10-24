<?php

declare(strict_types=1);

namespace Weline\Storage\Service;

use Weline\Framework\Runtime\RequestResetException;
use Weline\Framework\Runtime\RequestResetterInterface;
use Weline\Framework\Runtime\ProcessSharedInterface;

final class StorageRequestResetter implements ProcessSharedInterface, RequestResetterInterface
{
    public function resetRequest(): void
    {
        $failures = [];
        try {
            $resources = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Storage\Api\Runtime\StorageRequestResourceRegistryInterface::class);
            $resources->closeAll();
        } catch (\Throwable $throwable) {
            RequestResetException::append($failures, 'request_resources', $throwable);
        }

        try {
            $storageManager = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Storage\Service\StorageManagerV2::class);
            $storageManager->resetRequestState();
        } catch (\Throwable $throwable) {
            RequestResetException::append($failures, 'storage_manager_state', $throwable);
        }

        if ($failures !== []) {
            throw new RequestResetException('storage_request_resetter', $failures);
        }
    }
}
