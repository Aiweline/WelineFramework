<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Runtime;

final class ResumableTaskStoreException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
