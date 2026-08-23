<?php

declare(strict_types=1);

namespace Weline\Storage\Service;

use Weline\Storage\Api\Runtime\StorageClientLeaseInterface;
use Weline\Storage\Api\Runtime\StorageRequestResourceFactoryInterface;
use Weline\Storage\Api\Runtime\StorageRequestResourceRegistryInterface;
use Weline\Storage\Api\Runtime\StorageRequestStreamInterface;
use Weline\Storage\Api\Runtime\StorageTemporaryFileInterface;

final class StorageRequestResourceFactory implements StorageRequestResourceFactoryInterface
{
    public function __construct(private readonly StorageRequestResourceRegistryInterface $resources)
    {
    }

    public function stream(
        mixed $stream,
        string $kind = StorageRequestStreamInterface::KIND_LOCAL_FILE,
        ?callable $closer = null,
    ): StorageRequestStreamInterface {
        return new StorageRequestStream(
            $stream,
            $this->resources,
            $kind,
            $closer === null ? null : \Closure::fromCallable($closer),
        );
    }

    public function temporaryFile(string $directory, string $prefix): StorageTemporaryFileInterface
    {
        return StorageTemporaryFile::create($directory, $prefix, $this->resources);
    }

    public function clientLease(object $client, ?callable $closeCallback = null): StorageClientLeaseInterface
    {
        return new StorageClientLease($client, $this->resources, $closeCallback);
    }
}
