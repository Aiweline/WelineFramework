<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable\Runner;

/**
 * Result of a fresh, identity-aware OS process probe.
 */
final class RuntimeProcessProbe
{
    public const RUNNING = 'running';
    public const EXITED = 'exited';
    public const UNKNOWN = 'unknown';
    public const IDENTITY_MISMATCH = 'identity_mismatch';

    private const ALLOWED_STATES = [
        self::RUNNING,
        self::EXITED,
        self::UNKNOWN,
        self::IDENTITY_MISMATCH,
    ];

    public function __construct(
        public readonly RuntimeProcessIdentity $identity,
        public readonly string $state,
        public readonly string $reason,
        public readonly bool $terminated = false,
        public readonly bool $released = false,
    ) {
        if (!in_array($this->state, self::ALLOWED_STATES, true)) {
            throw new \InvalidArgumentException('Unknown Runtime Runner process state.');
        }
    }

    public static function unknown(RuntimeProcessIdentity $identity, string $reason): self
    {
        return new self($identity, self::UNKNOWN, $reason);
    }

    /**
     * @param array{state?:string,reason?:string,terminated?:bool,released?:bool} $result
     */
    public static function fromProcesser(RuntimeProcessIdentity $identity, array $result): self
    {
        $state = (string) ($result['state'] ?? self::UNKNOWN);
        if (!in_array($state, self::ALLOWED_STATES, true)) {
            $state = self::UNKNOWN;
        }

        return new self(
            identity: $identity,
            state: $state,
            reason: (string) ($result['reason'] ?? 'process_probe_unknown'),
            terminated: (bool) ($result['terminated'] ?? false),
            released: (bool) ($result['released'] ?? ($state === self::EXITED || $state === self::IDENTITY_MISMATCH)),
        );
    }

    public function isRunning(): bool
    {
        return $this->state === self::RUNNING;
    }

    /**
     * A new fencing generation may be claimed only after a CAS in the task
     * store. Identity mismatch means the old PID lease is no longer ours; no
     * signal is ever sent to that PID.
     */
    public function allowsRecovery(): bool
    {
        return $this->state === self::EXITED || $this->state === self::IDENTITY_MISMATCH;
    }
}
