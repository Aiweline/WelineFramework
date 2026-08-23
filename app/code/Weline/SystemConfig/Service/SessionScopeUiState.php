<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Service;

use Weline\Framework\Session\Session;
use Weline\SystemConfig\Api\Scope\ScopeUiStateInterface;

final class SessionScopeUiState implements ScopeUiStateInterface
{
    public function __construct(private readonly Session $session)
    {
    }

    public function read(string $key): ?array
    {
        $value = $this->session->get($key);

        return \is_array($value) ? $value : null;
    }

    public function write(string $key, array $value): void
    {
        $this->session->set($key, $value);
    }
}
