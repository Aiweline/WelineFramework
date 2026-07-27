<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Query\Store;

interface FrontendWorkerCredentialStoreInterface
{
    /**
     * @template T
     * @param callable(FrontendWorkerCredentialTransactionInterface):T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed;

    public function driver(): string;

    public function isShared(): bool;
}
