<?php

declare(strict_types=1);

namespace Weline\Catalog\Exception;

final class CatalogScopeForbiddenException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message,
        public readonly string $errorCode = 'catalog_scope_forbidden',
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
