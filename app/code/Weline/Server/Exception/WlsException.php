<?php
declare(strict_types=1);

namespace Weline\Server\Exception;

class WlsException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $context
     * @param list<string> $diagnostics
     */
    public function __construct(
        private readonly string $wlsErrorCode,
        string $message,
        private readonly array $context = [],
        private readonly array $diagnostics = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct('[' . $wlsErrorCode . '] ' . $message, 0, $previous);
    }

    public function getWlsErrorCode(): string
    {
        return $this->wlsErrorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * @return list<string>
     */
    public function getDiagnostics(): array
    {
        return $this->diagnostics;
    }
}
