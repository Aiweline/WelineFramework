<?php

declare(strict_types=1);

namespace Weline\Storage\Api\Runtime;

/** Public factory that keeps consumers independent from Storage internals. */
interface StorageRequestResourceFactoryInterface
{
    /** @param resource $stream */
    public function stream(
        mixed $stream,
        string $kind = StorageRequestStreamInterface::KIND_LOCAL_FILE,
        ?callable $closer = null,
    ): StorageRequestStreamInterface;

    public function temporaryFile(string $directory, string $prefix): StorageTemporaryFileInterface;

    public function clientLease(object $client, ?callable $closeCallback = null): StorageClientLeaseInterface;
}
