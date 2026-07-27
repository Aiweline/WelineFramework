<?php

declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

final class NamespaceInvalidationProtocolException extends \InvalidArgumentException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
