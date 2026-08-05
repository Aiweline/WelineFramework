<?php

declare(strict_types=1);

namespace Weline\CustomerAsset\Service;

use Weline\CustomerAsset\Api\CustomerAssetConflictInterface;

final class CustomerAssetConflictException extends \RuntimeException implements CustomerAssetConflictInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly string $errorCode,
        string $message = '',
        public readonly array $context = [],
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : $errorCode, $code, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }
}
