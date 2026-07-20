<?php

declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

/** Worker-local token state shared only by the reader/writer cache channels. */
final class TlsSessionCacheTokenState
{
    private ?string $token = null;

    public function current(): ?string
    {
        return $this->token;
    }

    public function remember(string $token): string
    {
        if ($token === '') {
            throw new \InvalidArgumentException('TLS session-cache token cannot be empty.');
        }

        return $this->token = $token;
    }

    public function invalidate(?string $rejectedToken): void
    {
        if (!\is_string($rejectedToken)
            || $rejectedToken === ''
            || !\is_string($this->token)
            || !\hash_equals($this->token, $rejectedToken)
        ) {
            return;
        }

        $this->token = null;
    }
}
