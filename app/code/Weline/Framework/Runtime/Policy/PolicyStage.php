<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Policy;

/**
 * Runtime policy execution stages. The order is part of the public contract.
 */
enum PolicyStage: string
{
    case CONNECTION = 'connection';
    case TLS = 'tls';
    case MANDATORY_REQUEST = 'mandatory_request';
    case CACHE = 'cache';
    case DEEP_REQUEST = 'deep_request';
    case RESPONSE = 'response';

    public function order(): int
    {
        return match ($this) {
            self::CONNECTION => 10,
            self::TLS => 20,
            self::MANDATORY_REQUEST => 30,
            self::CACHE => 40,
            self::DEEP_REQUEST => 50,
            self::RESPONSE => 60,
        };
    }
}
