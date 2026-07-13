<?php

declare(strict_types=1);

namespace Weline\Server\Security;

/**
 * Immutable result of the public L4 accept gate.
 *
 * This value intentionally contains no socket or service object so the same
 * decision contract can be consumed by Dispatcher and direct Workers.
 */
final readonly class ConnectionAcceptDecision
{
    private function __construct(
        public bool $allowed,
        public string $peerIp,
        public string $reason,
        public bool $whitelisted,
        public bool $trustedSource,
        public float $incompleteDeadline,
    ) {
    }

    public static function allow(
        string $peerIp,
        bool $whitelisted,
        bool $trustedSource,
        float $incompleteDeadline,
    ): self {
        return new self(
            allowed: true,
            peerIp: $peerIp,
            reason: 'accepted',
            whitelisted: $whitelisted,
            trustedSource: $trustedSource,
            incompleteDeadline: $incompleteDeadline,
        );
    }

    public static function deny(
        string $peerIp,
        string $reason,
        bool $whitelisted = false,
        bool $trustedSource = false,
    ): self {
        return new self(
            allowed: false,
            peerIp: $peerIp,
            reason: $reason,
            whitelisted: $whitelisted,
            trustedSource: $trustedSource,
            incompleteDeadline: 0.0,
        );
    }
}
