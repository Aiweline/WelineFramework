<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Query\Store;

final class FrontendWorkerCredentialType
{
    public const MAX_RETAINED_STREAM_TICKET_BYTES = 8 * 1024 * 1024;

    public const SESSION = 'session';
    public const NONCE = 'nonce';
    public const SCOPE_BOOTSTRAP = 'scope_bootstrap';
    public const BACKEND_BOOTSTRAP = 'backend_bootstrap';
    public const STREAM_TICKET = 'stream_ticket';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::SESSION,
            self::NONCE,
            self::SCOPE_BOOTSTRAP,
            self::BACKEND_BOOTSTRAP,
            self::STREAM_TICKET,
        ];
    }

    public static function assert(string $type): void
    {
        if (!\in_array($type, self::all(), true)) {
            throw new \InvalidArgumentException('Unsupported Worker credential type.');
        }
    }

    public static function retainedByteLimit(string $type): ?int
    {
        self::assert($type);
        return $type === self::STREAM_TICKET
            ? self::MAX_RETAINED_STREAM_TICKET_BYTES
            : null;
    }
}
